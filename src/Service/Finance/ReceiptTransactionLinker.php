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

        // If multiple or zero found, we might need more complex matching (merchant name fuzzy match)
        // For now, only auto-link if unique match by date/amount.

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
