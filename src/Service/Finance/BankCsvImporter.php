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
        'Номер сметка на наредителя / получателя',
        'Вид на трансакцията',
        'Свързваща референция',
        'Дебит BGN',
        'Кредит BGN',
        'Валута',
        'Транзакционна сума',
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

        $headers = fgetcsv($handle, 0, ',');
        if ($headers) {
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
        }
        // Simple header check (could be more robust)
        if (!$headers || !in_array(trim($headers[0]), ['Дата', '"Дата"', 'Дата ', '"Дата" '])) {
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

        while (($row = fgetcsv($handle, 0, ',')) !== false) {
            if (empty($row) || count($row) < count($headerMap)) {
                continue;
            }

            $stats['total']++;

            try {
                $data = $this->mapRow($row, $headerMap, $filePath);
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
                $transaction->setCurrency($data['currency']);
                $transaction->setExchangeRate($data['exchange_rate']);
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

    private function mapRow(array $row, array $headerMap, string $filePath = ''): array
    {
        $get = function(string $key) use ($headerMap, $row) {
            // Try exact match first
            if (isset($headerMap[$key])) {
                return isset($row[$headerMap[$key]]) ? trim($row[$headerMap[$key]]) : null;
            }
            // Try prefix matching across headerMap keys
            foreach ($headerMap as $header => $index) {
                if (str_starts_with($header, $key)) {
                    return isset($row[$index]) ? trim($row[$index]) : null;
                }
            }
            // Fallback for counterparty account header variant ("Номер сметка на наредителя / получателя")
            if ($key === 'IBAN/Сметка на Наредител/Получател') {
                foreach ($headerMap as $header => $index) {
                    if (str_starts_with($header, 'Номер сметка') || str_starts_with($header, 'IBAN/Сметка')) {
                        return isset($row[$index]) ? trim($row[$index]) : null;
                    }
                }
            }
            return null;
        };

        // Parse date (Bulgarian format is often DD.MM.YYYY)
        $dateStr = $get('Дата');
        $date = \DateTimeImmutable::createFromFormat('d.m.Y', $dateStr);
        if (!$date) {
             // Fallback to generic parser
             $date = new \DateTimeImmutable($dateStr);
        }

        $isBefore2026 = $date < new \DateTimeImmutable('2026-01-01');

        $currency = $isBefore2026 ? 'BGN' : 'EUR';
        if (str_contains(basename($filePath), '2026')) {
            $currency = 'EUR';
        }
        foreach ($headerMap as $header => $index) {
            if (str_contains($header, 'EUR')) {
                $currency = 'EUR';
            }
        }
        $isEur = ($currency === 'EUR');

        $exchangeRateStr = $get('Валутен курс');
        $exchangeRateStr = $exchangeRateStr ? str_replace(',', '.', $exchangeRateStr) : null;
        $exchangeRate = $exchangeRateStr ? (float)$exchangeRateStr : ($isBefore2026 ? 1.95583 : 1.0);

        $debitKey = $isBefore2026 ? 'Дебит BGN' : ('Дебит ' . ($isEur ? 'EUR' : 'BGN'));
        $creditKey = $isBefore2026 ? 'Кредит BGN' : ('Кредит ' . ($isEur ? 'EUR' : 'BGN'));

        $debit = $get($debitKey);
        if ($debit === null || $debit === '') {
            $debit = $get('Дебит');
        }
        $credit = $get($creditKey);
        if ($credit === null || $credit === '') {
            $credit = $get('Кредит');
        }

        // Normalize decimals (Bulgarian CSVs often use comma)
        $debit = $debit ? str_replace(',', '.', $debit) : null;
        $credit = $credit ? str_replace(',', '.', $credit) : null;

        // Convert to EUR: before Jan 1st 2026, values are in BGN, convert to EUR by dividing by exchangeRate (1.95583)
        $debitEur = null;
        if ($debit !== null && $debit !== '') {
            if ($isBefore2026) {
                $debitEur = number_format((float)$debit / $exchangeRate, 2, '.', '');
            } else {
                $debitEur = number_format((float)$debit * $exchangeRate, 2, '.', '');
            }
        }
        $creditEur = null;
        if ($credit !== null && $credit !== '') {
            if ($isBefore2026) {
                $creditEur = number_format((float)$credit / $exchangeRate, 2, '.', '');
            } else {
                $creditEur = number_format((float)$credit * $exchangeRate, 2, '.', '');
            }
        }

        return [
            'date' => $date->format('Y-m-d'),
            'description' => $get('Основание'),
            'counterparty' => $get('Наредител/Получател'),
            'counterparty_account' => $get('IBAN/Сметка на Наредител/Получател'),
            'transaction_type' => $get('Вид на трансакцията'),
            'reference' => $get('Свързваща референция'),
            'debit' => $debitEur,
            'currency' => $currency,
            'exchange_rate' => number_format($exchangeRate, 6, '.', ''),
            'credit' => $creditEur,
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
