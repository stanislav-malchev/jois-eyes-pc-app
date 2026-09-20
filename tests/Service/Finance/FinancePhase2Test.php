<?php

namespace App\Tests\Service\Finance;

use App\Entity\Category;
use App\Entity\LineItem;
use App\Entity\Product;
use App\Entity\Receipt;
use App\Entity\Transaction;
use App\Service\Finance\ProductLinker;
use App\Service\Finance\ReceiptOcrProcessor;
use App\Service\Finance\ReceiptTransactionLinker;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

class FinancePhase2Test extends TestCase
{
    private $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
    }

    public function testReceiptOcrProcessor()
    {
        $processor = new ReceiptOcrProcessor($this->entityManager);

        $ocrData = [
            'merchant' => 'Lidl',
            'date' => '2026-09-20',
            'total_bgn' => 42.50,
            'items' => [
                [
                    'description' => 'Milk',
                    'quantity' => 2,
                    'unit' => 'pcs',
                    'unit_price' => 2.50,
                    'total' => 5.00,
                    'category_hint' => 'Groceries'
                ]
            ]
        ];

        $this->entityManager->expects($this->atLeastOnce())
            ->method('persist');

        $receipt = $processor->processOcrData($ocrData);

        $this->assertInstanceOf(Receipt::class, $receipt);
        $this->assertEquals('Lidl', $receipt->getMerchant());
        $this->assertEquals('42.5', (float)$receipt->getTotalBgn());
        $this->assertEquals('2026-09-20', $receipt->getDate()->format('Y-m-d'));
    }

    public function testReceiptTransactionLinker()
    {
        $linker = new ReceiptTransactionLinker($this->entityManager);

        $receipt = new Receipt();
        $receipt->setDate(new \DateTimeImmutable('2026-09-20'));
        $receipt->setTotalBgn('42.50');

        $transaction = new Transaction();
        $transaction->setDate(new \DateTimeImmutable('2026-09-20'));
        $transaction->setDebitBgn('42.50');

        $query = $this->createMock(\Doctrine\ORM\Query::class);
        $query->method('getResult')->willReturn([$transaction]);

        $qb = $this->getMockBuilder(\Doctrine\ORM\QueryBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['select', 'from', 'leftJoin', 'where', 'andWhere', 'setParameter', 'getQuery'])
            ->getMock();
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('leftJoin')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $this->entityManager->method('createQueryBuilder')->willReturn($qb);

        $linked = $linker->linkReceipt($receipt);

        $this->assertTrue($linked);
        $this->assertSame($transaction, $receipt->getTransaction());
        $this->assertEquals('matched_to_transaction', $receipt->getStatus());
    }

    public function testProductLinker()
    {
        $linker = new ProductLinker($this->entityManager);

        $receipt = new Receipt();
        $receipt->setDate(new \DateTimeImmutable('2026-09-20'));

        $lineItem = new LineItem();
        $lineItem->setReceipt($receipt);
        $lineItem->setDescription('Test Product');
        $lineItem->setQuantity('1');
        $lineItem->setTotalBgn('10.00');
        $lineItem->setCategoryHint('Test Category');

        $productRepo = $this->createMock(EntityRepository::class);
        $categoryRepo = $this->createMock(EntityRepository::class);
        $lineItemRepo = $this->createMock(EntityRepository::class);

        $this->entityManager->method('getRepository')->willReturnCallback(function($class) use ($productRepo, $categoryRepo, $lineItemRepo) {
            if ($class === Product::class) return $productRepo;
            if ($class === Category::class) return $categoryRepo;
            if ($class === LineItem::class) return $lineItemRepo;
            return null;
        });

        // Product doesn't exist yet
        $productRepo->method('findOneBy')->with(['name' => 'Test Product'])->willReturn(null);
        // Category doesn't exist yet
        $categoryRepo->method('findOneBy')->with(['name' => 'Test Category'])->willReturn(null);

        // Mocking line items for stats update
        $lineItemRepo->method('findBy')->willReturn([$lineItem]);

        $linker->linkLineItem($lineItem);

        $product = $lineItem->getProduct();
        $this->assertInstanceOf(Product::class, $product);
        $this->assertEquals('Test Product', $product->getName());
        $this->assertEquals('Test Category', $product->getCategory()->getName());
        $this->assertEquals('10', (float)$product->getLifetimeSpendBgn());
    }
}
