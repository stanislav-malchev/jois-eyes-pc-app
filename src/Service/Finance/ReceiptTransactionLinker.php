<?php

namespace App\Service\Finance;

use App\Entity\Receipt;
use App\Entity\Transaction;
use Doctrine\ORM\EntityManagerInterface;

class ReceiptTransactionLinker
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Tries to link an unmatched Receipt to a Transaction.
     */
    public function linkReceipt(Receipt $receipt): bool
    {
        if ($receipt->getTransaction() !== null) {
            return true;
        }

        if (!$receipt->getDate() || !$receipt->getTotalBgn()) {
            return false;
        }

        // Search for a transaction on the same day with the same amount (as debit)
        // that is not already linked to another receipt.

        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('t')
            ->from(Transaction::class, 't')
            ->leftJoin(Receipt::class, 'r', 'WITH', 'r.transaction = t')
            ->where('t.date = :date')
            ->andWhere('t.debitBgn = :amount')
            ->andWhere('r.id IS NULL')
            ->andWhere('t.softDeleted = false')
            ->setParameter('date', $receipt->getDate())
            ->setParameter('amount', $receipt->getTotalBgn());

        $transactions = $qb->getQuery()->getResult();

        if (count($transactions) === 1) {
            $transaction = $transactions[0];
            $receipt->setTransaction($transaction);
            $receipt->setStatus('matched_to_transaction');
            return true;
        }

        // Fuzzy matching on merchant name if amount matches but date might be slightly off
        // OR if date matches but amount is slightly different (e.g. tip)
        // For now, let's implement the suggested: same date, slight amount discrepancy + merchant fuzzy match

        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('t')
            ->from(Transaction::class, 't')
            ->leftJoin(Receipt::class, 'r', 'WITH', 'r.transaction = t')
            ->where('t.date = :date')
            ->andWhere('r.id IS NULL')
            ->andWhere('t.softDeleted = false')
            ->setParameter('date', $receipt->getDate());

        $potentialTransactions = $qb->getQuery()->getResult();
        $bestMatch = null;
        $bestScore = 999;

        foreach ($potentialTransactions as $transaction) {
            $amountDiff = abs((float)$transaction->getDebitBgn() - (float)$receipt->getTotalBgn());
            // If amount matches exactly, we already tried unique match.
            // Here we look for amount within 20% or 10 BGN discrepancy (tips)
            if ($amountDiff > 0 && $amountDiff > 10 && $amountDiff > (float)$receipt->getTotalBgn() * 0.2) {
                continue;
            }

            $lev = levenshtein(strtolower($receipt->getMerchant() ?? ''), strtolower($transaction->getCounterparty() ?? ''));
            if ($lev < 5 && $lev < $bestScore) {
                $bestScore = $lev;
                $bestMatch = $transaction;
            }
        }

        if ($bestMatch) {
            $receipt->setTransaction($bestMatch);
            $receipt->setStatus('matched_to_transaction');
            return true;
        }

        return false;
    }

    /**
     * Link all unmatched receipts.
     */
    public function linkAllUnmatched(): int
    {
        $receipts = $this->entityManager->getRepository(Receipt::class)->findBy(['status' => 'unmatched']);
        $count = 0;
        foreach ($receipts as $receipt) {
            if ($this->linkReceipt($receipt)) {
                $count++;
            }
        }
        $this->entityManager->flush();
        return $count;
    }
}
