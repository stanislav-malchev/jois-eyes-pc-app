<?php

namespace App\Tests\Service\Finance;

use App\Entity\Account;
use App\Entity\Transaction;
use App\Service\Finance\BankCsvImporter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class BankCsvImporterTest extends TestCase
{
    private $entityManager;
    private $importer;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->importer = new BankCsvImporter($this->entityManager);
    }

    public function testCalculateFingerprint()
    {
        $data = [
            'date' => '2026-06-28',
            'description' => 'ПОС ПЛАЩАНЕ НА ТЕРМИНАЛ DKS PARKING',
            'counterparty' => 'DKS PARKING',
            'transaction_type' => 'КАРТОВА ОПЕРАЦИЯ',
            'debit' => '4.00',
            'credit' => null,
        ];

        $reflection = new \ReflectionClass(BankCsvImporter::class);
        $method = $reflection->getMethod('calculateFingerprint');
        $method->setAccessible(true);

        $fingerprint = $method->invoke($this->importer, $data);

        $this->assertEquals(64, strlen($fingerprint));

        // Test same data produces same fingerprint
        $fingerprint2 = $method->invoke($this->importer, $data);
        $this->assertEquals($fingerprint, $fingerprint2);

        // Test different amount produces different fingerprint
        $data['debit'] = '5.00';
        $fingerprint3 = $method->invoke($this->importer, $data);
        $this->assertNotEquals($fingerprint, $fingerprint3);
    }

    public function testClassifySoftDelete()
    {
        $transaction = new Transaction();
        $transaction->setDescription('ПРЕВОД МЕЖДУ МОИ СМЕТКИ');
        $transaction->setTransactionType('ВЪТРЕШНОБАНКОВ ПРЕВОД');

        $reflection = new \ReflectionClass(BankCsvImporter::class);
        $method = $reflection->getMethod('classifySoftDelete');
        $method->setAccessible(true);

        $method->invoke($this->importer, $transaction);

        $this->assertTrue($transaction->isSoftDeleted());
        $this->assertEquals('own_transfer', $transaction->getSoftDeleteReason());
    }

    public function testMapRowWithPrefixMatchingAndBom()
    {
        $csvContent = "\xEF\xBB\xBFДата,Основание,Наредител/Получател,Номер сметка на наредителя / получателя,Вид на трансакцията,Свързваща референция,Дебит BGN,Кредит BGN\n" .
                      "01.06.2025,TEST DESC,TEST COUNTERPARTY,BG12STSA30000000000000,КАРТОВА ОПЕРАЦИЯ,,\"10,50\",\n";
        
        $tmpFile = tempnam(sys_get_temp_dir(), 'dsk_test_');
        file_put_contents($tmpFile, $csvContent);

        $account = new Account();
        $account->setName('Test Account');

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        $this->entityManager->method('getRepository')
            ->with(Transaction::class)
            ->willReturn($repo);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (Transaction $t) {
                return $t->getDate()->format('Y-m-d') === '2025-06-01'
                    && $t->getDescription() === 'TEST DESC'
                    && $t->getCounterparty() === 'TEST COUNTERPARTY'
                    && $t->getCounterpartyAccount() === 'BG12STSA30000000000000'
                    && $t->getDebitBgn() === '10.50';
            }));

        $stats = $this->importer->import($tmpFile, $account);
        unlink($tmpFile);

        $this->assertEquals(1, $stats['imported']);
    }
}
