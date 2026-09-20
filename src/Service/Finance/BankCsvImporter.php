<?php

namespace App\Service\Finance;

use App\Entity\Account;
use App\Entity\Transaction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class BankCsvImporter
{
    private const BULGARIAN_HEADERS = [
        'Дата',
        'Основание',
        'Наредител/Получател',
        'IBAN/Сметка на Наредител/Получател',
        'Вид на трансакцията',
        'Свързваща референция',
        'Дебит',
        'Кредит',
    ];

    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    public function import(string $filePath, Account $account): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \InvalidArgumentException("File not found or not readable: $filePath");
        }

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new \RuntimeException("Failed to open file: $filePath");
        }

        $headers = fgetcsv($handle, 0, ';');
        // Simple header check (could be more robust)
        if (!$headers || !in_array('Дата', $headers)) {
            fclose($handle);
            throw new \RuntimeException("Invalid CSV format: Missing 'Дата' header.");
        }

        $headerMap = array_flip($headers);

        $stats = [
            'total' => 0,
            'imported' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            if (empty($row) || count($row) < count($headerMap)) {
                continue;
            }

            $stats['total']++;

            try {
                $data = $this->mapRow($row, $headerMap);
                $fingerprint = $this->calculateFingerprint($data);

                if ($this->exists($fingerprint, $account)) {
                    $stats['skipped']++;
                    continue;
                }

                $transaction = new Transaction();
                $transaction->setAccount($account);
                $transaction->setDate(new \DateTimeImmutable($data['date']));
                $transaction->setDescription($data['description']);
                $transaction->setCounterparty($data['counterparty']);
                $transaction->setCounterpartyAccount($data['counterparty_account']);
                $transaction->setTransactionType($data['transaction_type']);
                $transaction->setReference($data['reference']);
                $transaction->setDebitBgn($data['debit']);
                $transaction->setCreditBgn($data['credit']);
                $transaction->setFingerprint($fingerprint);
                $transaction->setSourceFile(basename($filePath));

                $this->classifySoftDelete($transaction);

                $this->entityManager->persist($transaction);
                $stats['imported']++;

                // Flush in batches if needed, but for now simple persist
            } catch (\Exception $e) {
                $stats['errors']++;
                // Log error
            }
        }

        fclose($handle);
        $this->entityManager->flush();

        // After flush, try to pair internal transfers
        $this->pairInternalTransfers($account);

        return $stats;
    }

    private function mapRow(array $row, array $headerMap): array
    {
        $get = fn($key) => isset($headerMap[$key]) ? trim($row[$headerMap[$key]]) : null;

        $debit = $get('Дебит');
        $credit = $get('Кредит');

        // Normalize decimals (Bulgarian CSVs often use comma)
        $debit = $debit ? str_replace(',', '.', $debit) : null;
        $credit = $credit ? str_replace(',', '.', $credit) : null;

        // Parse date (Bulgarian format is often DD.MM.YYYY)
        $dateStr = $get('Дата');
        $date = \DateTimeImmutable::createFromFormat('d.m.Y', $dateStr);
        if (!$date) {
             // Fallback to generic parser
             $date = new \DateTimeImmutable($dateStr);
        }

        return [
            'date' => $date->format('Y-m-d'),
            'description' => $get('Основание'),
            'counterparty' => $get('Наредител/Получател'),
            'counterparty_account' => $get('IBAN/Сметка на Наредител/Получател'),
            'transaction_type' => $get('Вид на трансакцията'),
            'reference' => $get('Свързваща референция'),
            'debit' => $debit,
            'credit' => $credit,
        ];
    }

    private function calculateFingerprint(array $data): string
    {
        $amount = $data['debit'] ?? $data['credit'] ?? '0.00';
        $amount = number_format((float)abs($amount), 2, '.', '');

        $key = sprintf(
            '%s|%s|%s|%s|%s',
            strtolower(trim($data['counterparty'] ?? '')),
            $data['date'],
            $amount,
            strtolower(trim($data['description'] ?? '')),
            strtolower(trim($data['transaction_type'] ?? ''))
        );

        return hash('sha256', $key);
    }

    private function exists(string $fingerprint, Account $account): bool
    {
        return (bool) $this->entityManager->getRepository(Transaction::class)->findOneBy([
            'fingerprint' => $fingerprint,
            'account' => $account,
        ]);
    }

    private function classifySoftDelete(Transaction $transaction): void
    {
        $desc = $transaction->getDescription();
        $type = $transaction->getTransactionType();

        $internalPatterns = [
            'own_transfer' => ['ПРЕВОД МЕЖДУ МОИ СМЕТКИ', 'ТРАНСФЕР МЕЖДУ СВОИ СМЕТКИ'],
            'credit_card_revolving' => ['РЕВОЛВИРАНЕ НА КРЕДИТНА КАРТА'],
            'loan_disbursement' => ['УСВОЯВАНЕ НА КРЕДИТ'],
        ];

        foreach ($internalPatterns as $reason => $patterns) {
            foreach ($patterns as $pattern) {
                if (mb_stripos($desc, $pattern) !== false || mb_stripos($type, $pattern) !== false) {
                    $transaction->setSoftDeleted(true);
                    $transaction->setSoftDeleteReason($reason);
                    return;
                }
            }
        }
    }

    private function pairInternalTransfers(Account $account): void
    {
        // Find unpaired soft-deleted transactions for this account
        $unpaired = $this->entityManager->getRepository(Transaction::class)->findBy([
            'account' => $account,
            'softDeleted' => true,
            'pairedWith' => null,
        ]);

        foreach ($unpaired as $t1) {
            $amount = $t1->getDebitBgn() ?: $t1->getCreditBgn();
            $isDebit = !empty($t1->getDebitBgn());

            // Look for a matching transaction on other accounts
            // Same day, same amount (but opposite side), 'own_transfer' reason
            $criteria = [
                'date' => $t1->getDate(),
                'softDeleted' => true,
                'softDeleteReason' => 'own_transfer',
                'pairedWith' => null,
            ];

            if ($isDebit) {
                $criteria['creditBgn'] = $amount;
            } else {
                $criteria['debitBgn'] = $amount;
            }

            $matches = $this->entityManager->getRepository(Transaction::class)->findBy($criteria);

            foreach ($matches as $t2) {
                if ($t1->getAccount()->getId()->equals($t2->getAccount()->getId())) {
                    continue; // Skip same account
                }

                $t1->setPairedWith($t2);
                $t2->setPairedWith($t1);
                $this->entityManager->persist($t1);
                $this->entityManager->persist($t2);
                break; // Paired
            }
        }

        $this->entityManager->flush();
    }
}
