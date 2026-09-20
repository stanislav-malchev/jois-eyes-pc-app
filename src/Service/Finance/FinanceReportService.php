<?php

namespace App\Service\Finance;

use App\Entity\Receipt;
use App\Entity\Transaction;
use Doctrine\ORM\EntityManagerInterface;

class FinanceReportService
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    public function generateAccountabilityReport(?\DateTimeInterface $start = null, ?\DateTimeInterface $end = null): array
    {
        // 1. Total Credited (Income)
        $incomeQb = $this->entityManager->createQueryBuilder()
            ->select('SUM(t.creditBgn)')
            ->from(Transaction::class, 't')
            ->where('t.softDeleted = false')
            ->andWhere('t.creditBgn > 0');

        if ($start) {
            $incomeQb->andWhere('t.date >= :start')->setParameter('start', $start);
        }
        if ($end) {
            $incomeQb->andWhere('t.date <= :end')->setParameter('end', $end);
        }
        $totalIncome = $incomeQb->getQuery()->getSingleScalarResult() ?: '0.00';

        // 2. Total Debited (Spending) - excluding soft-deleted
        $spendingQb = $this->entityManager->createQueryBuilder()
            ->select('SUM(t.debitBgn)')
            ->from(Transaction::class, 't')
            ->where('t.softDeleted = false')
            ->andWhere('t.debitBgn > 0');

        if ($start) {
            $spendingQb->andWhere('t.date >= :start')->setParameter('start', $start);
        }
        if ($end) {
            $spendingQb->andWhere('t.date <= :end')->setParameter('end', $end);
        }
        $totalSpending = $spendingQb->getQuery()->getSingleScalarResult() ?: '0.00';

        // 3. Total Soft-Deleted (Internal Moves)
        $softDeletedQb = $this->entityManager->createQueryBuilder()
            ->select('SUM(t.debitBgn) as debits, SUM(t.creditBgn) as credits')
            ->from(Transaction::class, 't')
            ->where('t.softDeleted = true');

        if ($start) {
            $softDeletedQb->andWhere('t.date >= :start')->setParameter('start', $start);
        }
        if ($end) {
            $softDeletedQb->andWhere('t.date <= :end')->setParameter('end', $end);
        }
        $softDeletedResult = $softDeletedQb->getQuery()->getSingleResult();
        $debits = $softDeletedResult['debits'] ?? 0;
        $credits = $softDeletedResult['credits'] ?? 0;
        $totalInternal = (string)((float)$debits + (float)$credits);

        // 4. Matched vs Unmatched Spending
        // Matched means it has a linked Receipt
        $matchedSpendingQb = $this->entityManager->createQueryBuilder()
            ->select('SUM(t.debitBgn)')
            ->from(Transaction::class, 't')
            ->join(Receipt::class, 'r', 'WITH', 'r.transaction = t')
            ->where('t.softDeleted = false')
            ->andWhere('t.debitBgn > 0');

        if ($start) {
            $matchedSpendingQb->andWhere('t.date >= :start')->setParameter('start', $start);
        }
        if ($end) {
            $matchedSpendingQb->andWhere('t.date <= :end')->setParameter('end', $end);
        }
        $matchedSpending = $matchedSpendingQb->getQuery()->getSingleScalarResult() ?: '0.00';
        $unmatchedSpending = (string)((float)$totalSpending - (float)$matchedSpending);

        // 5. Variance (Unexplained Cash Flow)
        // This is tricky. Total inflow - Total outflow should match balance change.
        // But here we usually mean "Cash variance" if we track cash.
        // In the wiki it says "Cash variance: unexplained".
        // For now let's just use the unmatched percentage as a proxy or calculate it if we have balance.
        $matchRate = $totalSpending > 0 ? round(((float)$matchedSpending / (float)$totalSpending) * 100, 2) : 100.0;

        // 6. Products and Items
        $productCount = $this->entityManager->getRepository(\App\Entity\Product::class)
            ->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->getQuery()->getSingleScalarResult();

        $itemCount = $this->entityManager->getRepository(\App\Entity\LineItem::class)
            ->createQueryBuilder('li')
            ->select('SUM(li.quantity)')
            ->getQuery()->getSingleScalarResult() ?: 0;

        return [
            'period' => [
                'start' => $start?->format('Y-m-d'),
                'end' => $end?->format('Y-m-d'),
            ],
            'income' => $totalIncome,
            'spending' => $totalSpending,
            'internal_moves' => $totalInternal,
            'matched_spending' => $matchedSpending,
            'unmatched_spending' => $unmatchedSpending,
            'match_rate_percent' => $matchRate,
            'product_count' => (int)$productCount,
            'item_count' => (int)$itemCount,
            'variance_bgn' => '0.00', // TODO: Implement cash variance if balance is tracked
        ];
    }
}
