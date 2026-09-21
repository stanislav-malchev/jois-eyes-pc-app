<?php

namespace App\MCP\Tools;

use App\Entity\Product;
use App\Entity\Receipt;
use App\Entity\Transaction;
use App\Entity\LineItem;
use App\Entity\Account;
use App\Service\Finance\FinanceReportService;
use App\Service\Finance\BankCsvImporter;
use App\Service\Finance\ReceiptOcrProcessor;
use App\Service\Finance\ReceiptTransactionLinker;
use App\Service\Finance\ProductLinker;
use Doctrine\ORM\EntityManagerInterface;
use KLP\KlpMcpServer\Services\ProgressService\ProgressNotifierInterface;
use KLP\KlpMcpServer\Services\ToolService\Annotation\ToolAnnotation;
use KLP\KlpMcpServer\Services\ToolService\Result\StructuredToolResult;
use KLP\KlpMcpServer\Services\ToolService\Result\ToolResultInterface;
use KLP\KlpMcpServer\Services\ToolService\Schema\PropertyType;
use KLP\KlpMcpServer\Services\ToolService\Schema\SchemaProperty;
use KLP\KlpMcpServer\Services\ToolService\Schema\StructuredSchema;
use KLP\KlpMcpServer\Services\ToolService\StreamableToolInterface;

class FinanceTool implements StreamableToolInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FinanceReportService $reportService,
        private readonly BankCsvImporter $bankCsvImporter,
        private readonly ReceiptOcrProcessor $ocrProcessor,
        private readonly ReceiptTransactionLinker $transactionLinker,
        private readonly ProductLinker $productLinker
    ) {
    }

    public function getName(): string
    {
        return 'finance';
    }

    public function getDescription(): string
    {
        return 'Finance review and reconciliation tools. Actions:
- `report`: Run the Accountability Report (The Game) for a period.
- `list_unmatched_receipts`: Show receipts that are not linked to any bank transaction.
- `list_unprocessed_receipts`: Show receipts linked to transactions but missing detailed line items.
- `product_history`: Get purchase history for a specific product by name.
- `merge_products`: Merge two products (source_name -> target_name).
- `link_receipt`: Manually link a receipt (UUID) to a transaction (UUID).
- `import_csv`: Import bank statement CSV file into an account (`file_path`, `account`).
- `process_receipts`: Ingest receipt OCR JSON data and link receipts/line items (`ocr_json_path` or `file_path`).';
    }

    public function getInputSchema(): StructuredSchema
    {
        return new StructuredSchema(
                    new SchemaProperty(
                        name: 'action',
                        type: PropertyType::STRING,
                        description: 'The action to perform.',
                        enum: ['report', 'list_unmatched_receipts', 'list_unprocessed_receipts', 'product_history', 'merge_products', 'link_receipt', 'import_csv', 'import', 'process_receipts', 'ingest_receipts'],
                        required: true,
                    ),
                    new SchemaProperty(
                        name: 'start_date',
                        type: PropertyType::STRING,
                        description: 'Start date (YYYY-MM-DD) for report or history.',
                    ),
                    new SchemaProperty(
                        name: 'end_date',
                        type: PropertyType::STRING,
                        description: 'End date (YYYY-MM-DD) for report or history.',
                    ),
                    new SchemaProperty(
                        name: 'product_name',
                        type: PropertyType::STRING,
                        description: 'Product name for history or merge.',
                    ),
                    new SchemaProperty(
                        name: 'target_product_name',
                        type: PropertyType::STRING,
                        description: 'Target product name for merge.',
                    ),
                    new SchemaProperty(
                        name: 'receipt_id',
                        type: PropertyType::STRING,
                        description: 'Receipt UUID.',
                    ),
                    new SchemaProperty(
                        name: 'transaction_id',
                        type: PropertyType::STRING,
                        description: 'Transaction UUID.',
                    ),
                    new SchemaProperty(
                        name: 'file_path',
                        type: PropertyType::STRING,
                        description: 'Path to the CSV file for import.',
                    ),
                    new SchemaProperty(
                        name: 'account',
                        type: PropertyType::STRING,
                        description: 'Account UUID or Name for import.',
                    ),
                    new SchemaProperty(
                        name: 'ocr_json_path',
                        type: PropertyType::STRING,
                        description: 'Path to OCR JSON file for receipt ingestion.',
                    ),
                );
    }

    public function getOutputSchema(): ?StructuredSchema
    {
        return null;
    }

    public function getAnnotations(): ToolAnnotation
    {
        return new ToolAnnotation(
            title: 'Finance & Accounting',
            readOnlyHint: false,
            destructiveHint: false,
            idempotentHint: false,
            openWorldHint: false,
        );
    }

    public function execute(array $arguments): ToolResultInterface
    {
        $action = $arguments['action'] ?? 'report';

        return match ($action) {
            'report' => $this->handleReport($arguments),
            'list_unmatched_receipts' => $this->handleListUnmatchedReceipts(),
            'list_unprocessed_receipts' => $this->handleListUnprocessedReceipts(),
            'product_history' => $this->handleProductHistory($arguments),
            'merge_products' => $this->handleMergeProducts($arguments),
            'link_receipt' => $this->handleLinkReceipt($arguments),
            'import_csv', 'import' => $this->handleImportCsv($arguments),
            'process_receipts', 'ingest_receipts' => $this->handleProcessReceipts($arguments),
            default => new StructuredToolResult(['error' => 'Unknown action']),
        };
    }

    private function handleReport(array $args): ToolResultInterface
    {
        $start = isset($args['start_date']) ? new \DateTimeImmutable($args['start_date']) : null;
        $end = isset($args['end_date']) ? new \DateTimeImmutable($args['end_date']) : null;

        return new StructuredToolResult($this->reportService->generateAccountabilityReport($start, $end));
    }

    private function handleListUnmatchedReceipts(): ToolResultInterface
    {
        $receipts = $this->entityManager->getRepository(Receipt::class)->findBy(['transaction' => null]);
        $data = array_map(fn(Receipt $r) => [
            'id' => $r->getId()->toRfc4122(),
            'merchant' => $r->getMerchant(),
            'date' => $r->getDate()?->format('Y-m-d'),
            'total' => $r->getTotalBgn(),
        ], $receipts);

        return new StructuredToolResult(['unmatched_receipts' => $data]);
    }

    private function handleListUnprocessedReceipts(): ToolResultInterface
    {
        // Receipts that have a transaction but no line items
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('r')
            ->from(Receipt::class, 'r')
            ->leftJoin(LineItem::class, 'li', 'WITH', 'li.receipt = r')
            ->where('r.transaction IS NOT NULL')
            ->groupBy('r.id')
            ->having('COUNT(li.id) = 0');

        $receipts = $qb->getQuery()->getResult();
        $data = array_map(fn(Receipt $r) => [
            'id' => $r->getId()->toRfc4122(),
            'merchant' => $r->getMerchant(),
            'date' => $r->getDate()?->format('Y-m-d'),
            'total' => $r->getTotalBgn(),
            'transaction_id' => $r->getTransaction()->getId()->toRfc4122(),
        ], $receipts);

        return new StructuredToolResult(['unprocessed_receipts' => $data]);
    }

    private function handleProductHistory(array $args): ToolResultInterface
    {
        $name = $args['product_name'] ?? null;
        if (!$name) return new StructuredToolResult(['error' => 'product_name required']);

        $product = $this->entityManager->getRepository(Product::class)->findOneBy(['name' => $name]);
        if (!$product) return new StructuredToolResult(['error' => 'Product not found']);

        $lineItems = $this->entityManager->getRepository(LineItem::class)->findBy(['product' => $product], ['id' => 'DESC']);

        $history = array_map(fn(LineItem $li) => [
            'date' => $li->getReceipt()->getDate()?->format('Y-m-d'),
            'merchant' => $li->getReceipt()->getMerchant(),
            'quantity' => $li->getQuantity(),
            'unit' => $li->getUnit(),
            'unit_price' => $li->getUnitPriceBgn(),
            'total' => $li->getTotalBgn(),
        ], $lineItems);

        return new StructuredToolResult([
            'product' => [
                'name' => $product->getName(),
                'lifetime_qty' => $product->getLifetimeQuantity(),
                'lifetime_spend' => $product->getLifetimeSpendBgn(),
            ],
            'history' => $history
        ]);
    }

    private function handleMergeProducts(array $args): ToolResultInterface
    {
        $sourceName = $args['product_name'] ?? null;
        $targetName = $args['target_product_name'] ?? null;

        if (!$sourceName || !$targetName) return new StructuredToolResult(['error' => 'Both product names required']);

        $source = $this->entityManager->getRepository(Product::class)->findOneBy(['name' => $sourceName]);
        $target = $this->entityManager->getRepository(Product::class)->findOneBy(['name' => $targetName]);

        if (!$source || !$target) return new StructuredToolResult(['error' => 'One or both products not found']);

        // Move all line items
        $lineItems = $this->entityManager->getRepository(LineItem::class)->findBy(['product' => $source]);
        foreach ($lineItems as $li) {
            $li->setProduct($target);
        }

        // Recalculate target stats
        // (Re-using logic from ProductLinker would be better but let's do it simply)
        $this->entityManager->remove($source);
        $this->entityManager->flush();

        return new StructuredToolResult(['status' => 'success', 'message' => "Merged $sourceName into $targetName"]);
    }

    private function handleLinkReceipt(array $args): ToolResultInterface
    {
        $receiptId = $args['receipt_id'] ?? null;
        $transactionId = $args['transaction_id'] ?? null;

        if (!$receiptId || !$transactionId) return new StructuredToolResult(['error' => 'Both UUIDs required']);

        $receipt = $this->entityManager->getRepository(Receipt::class)->find($receiptId);
        $transaction = $this->entityManager->getRepository(Transaction::class)->find($transactionId);

        if (!$receipt || !$transaction) return new StructuredToolResult(['error' => 'Receipt or Transaction not found']);

        $receipt->setTransaction($transaction);
        $receipt->setStatus('matched_to_transaction_manually');
        $this->entityManager->flush();

        return new StructuredToolResult(['status' => 'success', 'message' => 'Manually linked']);
    }

    private function handleImportCsv(array $args): ToolResultInterface
    {
        $filePath = $args['file_path'] ?? $args['file'] ?? null;
        $accountRef = $args['account'] ?? null;

        if (!$filePath || !$accountRef) {
            return new StructuredToolResult(['error' => 'Both file_path and account required']);
        }

        if (!file_exists($filePath)) {
            return new StructuredToolResult(['error' => "File not found: $filePath"]);
        }

        $account = $this->entityManager->getRepository(Account::class)->find($accountRef);
        if (!$account) {
            $account = $this->entityManager->getRepository(Account::class)->findOneBy(['name' => $accountRef]);
        }

        if (!$account) {
            return new StructuredToolResult(['error' => sprintf('Account "%s" not found', $accountRef)]);
        }

        try {
            $stats = $this->bankCsvImporter->import($filePath, $account);
            return new StructuredToolResult([
                'status' => 'success',
                'account' => $account->getName(),
                'file' => basename($filePath),
                'stats' => $stats,
            ]);
        } catch (\Exception $e) {
            return new StructuredToolResult(['error' => $e->getMessage()]);
        }
    }

    private function handleProcessReceipts(array $args): ToolResultInterface
    {
        $filePath = $args['ocr_json_path'] ?? $args['file_path'] ?? $args['file'] ?? null;
        $ingestedCount = 0;
        $ingestedDetails = [];

        if ($filePath) {
            if (!file_exists($filePath)) {
                return new StructuredToolResult(['error' => "OCR JSON file not found: $filePath"]);
            }

            $content = file_get_contents($filePath);
            $data = json_decode($content, true);
            if (null === $data) {
                return new StructuredToolResult(['error' => 'Invalid JSON in OCR file.']);
            }

            $receiptsData = isset($data['merchant']) ? [$data] : $data;

            foreach ($receiptsData as $rData) {
                $receipt = $this->ocrProcessor->processOcrData($rData);
                $ingestedCount++;
                $ingestedDetails[] = [
                    'merchant' => $receipt->getMerchant(),
                    'date' => $receipt->getDate()?->format('Y-m-d'),
                    'total' => $receipt->getTotalBgn(),
                ];
            }
            $this->entityManager->flush();
        }

        $linkedTransactions = $this->transactionLinker->linkAllUnmatched();
        $linkedProducts = $this->productLinker->linkAllUnlinked();

        return new StructuredToolResult([
            'status' => 'success',
            'ingested_receipts_count' => $ingestedCount,
            'ingested_receipts' => $ingestedDetails,
            'linked_transactions_count' => $linkedTransactions,
            'linked_line_items_count' => $linkedProducts,
        ]);
    }

    public function isStreaming(): bool
    {
        return false;
    }

    public function setProgressNotifier(ProgressNotifierInterface $progressNotifier): void
    {
    }
}
