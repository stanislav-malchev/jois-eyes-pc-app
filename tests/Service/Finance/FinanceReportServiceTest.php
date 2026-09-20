<?php

namespace App\Tests\Service\Finance;

use App\Entity\LineItem;
use App\Entity\Product;
use App\Entity\Receipt;
use App\Entity\Transaction;
use App\Service\Finance\FinanceReportService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

class FinanceReportServiceTest extends TestCase
{
    private $entityManager;
    private $reportService;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->reportService = new FinanceReportService($this->entityManager);
    }

    public function testGenerateAccountabilityReport()
    {
        // 1. Total Income Mock
        $incomeQuery = $this->createMock(Query::class);
        $incomeQuery->method('getSingleScalarResult')->willReturn('1000.00');

        // 2. Total Spending Mock
        $spendingQuery = $this->createMock(Query::class);
        $spendingQuery->method('getSingleScalarResult')->willReturn('500.00');

        // 3. Soft Deleted Mock
        $softDeletedQuery = $this->createMock(Query::class);
        $softDeletedQuery->method('getSingleResult')->willReturn(['debits' => '200.00', 'credits' => '100.00']);

        // 4. Matched Spending Mock
        $matchedQuery = $this->createMock(Query::class);
        $matchedQuery->method('getSingleScalarResult')->willReturn('375.00');

        // 5. Product Count Mock
        $productRepo = $this->createMock(EntityRepository::class);
        $productCountQuery = $this->createMock(Query::class);
        $productCountQuery->method('getSingleScalarResult')->willReturn(10);
        $productRepoQb = $this->createMock(QueryBuilder::class);
        $productRepoQb->method('select')->willReturnSelf();
        $productRepoQb->method('getQuery')->willReturn($productCountQuery);
        $productRepo->method('createQueryBuilder')->willReturn($productRepoQb);

        // 6. Item Count Mock
        $lineItemRepo = $this->createMock(EntityRepository::class);
        $itemCountQuery = $this->createMock(Query::class);
        $itemCountQuery->method('getSingleScalarResult')->willReturn(50);
        $lineItemRepoQb = $this->createMock(QueryBuilder::class);
        $lineItemRepoQb->method('select')->willReturnSelf();
        $lineItemRepoQb->method('getQuery')->willReturn($itemCountQuery);
        $lineItemRepo->method('createQueryBuilder')->willReturn($lineItemRepoQb);

        $this->entityManager->method('getRepository')->willReturnCallback(function($class) use ($productRepo, $lineItemRepo) {
            if ($class === Product::class) return $productRepo;
            if ($class === LineItem::class) return $lineItemRepo;
            return null;
        });

        $this->entityManager->expects($this->exactly(4))
            ->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls(
                $this->mockQb($incomeQuery),
                $this->mockQb($spendingQuery),
                $this->mockQb($softDeletedQuery),
                $this->mockQb($matchedQuery)
            );

        $report = $this->reportService->generateAccountabilityReport();

        $this->assertEquals('1000.00', $report['income']);
        $this->assertEquals('500.00', $report['spending']);
        $this->assertEquals('300', $report['internal_moves']); // 200 + 100
        $this->assertEquals('375.00', $report['matched_spending']);
        $this->assertEquals('125', $report['unmatched_spending']); // 500 - 375
        $this->assertEquals(75.0, $report['match_rate_percent']); // 375 / 500 * 100
        $this->assertEquals(10, $report['product_count']);
        $this->assertEquals(50, $report['item_count']);
    }

    private function mockQb($query)
    {
        $qb = $this->getMockBuilder(QueryBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['select', 'from', 'where', 'andWhere', 'setParameter', 'join', 'getQuery'])
            ->getMock();
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('join')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);
        return $qb;
    }
}
