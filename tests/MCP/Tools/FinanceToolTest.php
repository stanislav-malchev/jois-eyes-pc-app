<?php

namespace App\Tests\MCP\Tools;

use App\MCP\Tools\FinanceTool;
use App\Service\Finance\FinanceReportService;
use App\Service\Finance\BankCsvImporter;
use App\Service\Finance\ReceiptOcrProcessor;
use App\Service\Finance\ReceiptTransactionLinker;
use App\Service\Finance\ProductLinker;
use Doctrine\ORM\EntityManagerInterface;
use KLP\KlpMcpServer\Services\ToolService\Result\StructuredToolResult;
use PHPUnit\Framework\TestCase;

/**
 * FinanceTool is a thin MCP-protocol adapter over the finance repositories and
 * FinanceReportService. This covers the adapter itself — metadata, and above all
 * the input schema: the tool shipped unregistered and with a schema that passed a
 * raw array to StructuredSchema (which takes SchemaProperty objects), so every
 * tools/list call 500'd and the tool was invisible. The schema assertions below
 * are the regression guard for that.
 */
class FinanceToolTest extends TestCase
{
    private function tool(?FinanceReportService $reportService = null, ?BankCsvImporter $bankCsvImporter = null, ?ReceiptOcrProcessor $ocrProcessor = null, ?ReceiptTransactionLinker $transactionLinker = null, ?ProductLinker $productLinker = null): FinanceTool
    {
        return new FinanceTool(
            $this->createStub(EntityManagerInterface::class),
            $reportService ?? $this->createStub(FinanceReportService::class),
            $bankCsvImporter ?? $this->createStub(BankCsvImporter::class),
            $ocrProcessor ?? $this->createStub(ReceiptOcrProcessor::class),
            $transactionLinker ?? $this->createStub(ReceiptTransactionLinker::class),
            $productLinker ?? $this->createStub(ProductLinker::class),
        );
    }

    public function testMetadata(): void
    {
        $tool = $this->tool();

        self::assertSame('finance', $tool->getName());
        self::assertFalse($tool->isStreaming());
        self::assertFalse($tool->getAnnotations()->isReadOnlyHint());
        self::assertFalse($tool->getAnnotations()->isDestructiveHint());
        self::assertNull($tool->getOutputSchema());
    }

    /**
     * The schema must survive serialisation to JSON Schema — this is what broke:
     * StructuredSchema only accepts variadic SchemaProperty, never a raw array.
     */
    public function testInputSchemaSerialisesWithActionRequired(): void
    {
        $schema = $this->tool()->getInputSchema()->asArray();

        self::assertSame('object', $schema['type']);
        self::assertSame(['action'], $schema['required']);
        self::assertSame([
            'action',
            'start_date',
            'end_date',
            'product_name',
            'target_product_name',
            'receipt_id',
            'transaction_id',
            'file_path',
            'account',
            'ocr_json_path',
        ], array_keys($schema['properties']));
    }

    public function testActionEnumMatchesTheImplementedActions(): void
    {
        $properties = $this->tool()->getInputSchema()->asArray()['properties'];

        self::assertSame([
            'report',
            'list_unmatched_receipts',
            'list_unprocessed_receipts',
            'product_history',
            'merge_products',
            'link_receipt',
            'import_csv',
            'import',
            'process_receipts',
            'ingest_receipts',
        ], $properties['action']['enum']);
    }

    public function testReportDelegatesToReportServiceWithParsedDates(): void
    {
        $report = ['income' => '100.00', 'spending' => '40.00'];

        $reportService = $this->createMock(FinanceReportService::class);
        $reportService->expects(self::once())
            ->method('generateAccountabilityReport')
            ->with(
                self::equalTo(new \DateTimeImmutable('2025-01-01')),
                self::equalTo(new \DateTimeImmutable('2025-12-31')),
            )
            ->willReturn($report);

        $result = $this->tool($reportService)->execute([
            'action' => 'report',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);

        self::assertInstanceOf(StructuredToolResult::class, $result);
        self::assertSame($report, $result->getStructuredValue());
    }

    public function testUnknownActionReturnsAnErrorRatherThanThrowing(): void
    {
        $result = $this->tool()->execute(['action' => 'nonsense']);

        self::assertInstanceOf(StructuredToolResult::class, $result);
        self::assertSame(['error' => 'Unknown action'], $result->getStructuredValue());
    }

    public function testProductHistoryWithoutNameIsAnError(): void
    {
        $result = $this->tool()->execute(['action' => 'product_history']);

        self::assertSame(['error' => 'product_name required'], $result->getStructuredValue());
    }

    public function testImportCsvWithoutRequiredParamsIsAnError(): void
    {
        $result = $this->tool()->execute(['action' => 'import_csv']);

        self::assertSame(['error' => 'Both file_path and account required'], $result->getStructuredValue());
    }

    public function testProcessReceiptsMissingFileReturnsError(): void
    {
        $result = $this->tool()->execute([
            'action' => 'process_receipts',
            'ocr_json_path' => '/nonexistent/path/receipt.json',
        ]);

        self::assertSame(['error' => 'OCR JSON file not found: /nonexistent/path/receipt.json'], $result->getStructuredValue());
    }
}
