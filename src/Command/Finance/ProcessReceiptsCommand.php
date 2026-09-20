<?php

namespace App\Command\Finance;

use App\Service\Finance\ProductLinker;
use App\Service\Finance\ReceiptOcrProcessor;
use App\Service\Finance\ReceiptTransactionLinker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:finance:process-receipts',
    description: 'Processes OCR data and links receipts to transactions and products.',
)]
class ProcessReceiptsCommand extends Command
{
    public function __construct(
        private ReceiptOcrProcessor $ocrProcessor,
        private ReceiptTransactionLinker $transactionLinker,
        private ProductLinker $productLinker
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('ocr-json', null, InputOption::VALUE_REQUIRED, 'Path to a JSON file containing OCR data to ingest')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $ocrJsonPath = $input->getOption('ocr-json');

        if ($ocrJsonPath) {
            if (!file_exists($ocrJsonPath)) {
                $io->error(sprintf('File not found: %s', $ocrJsonPath));
                return Command::FAILURE;
            }

            $data = json_decode(file_get_contents($ocrJsonPath), true);
            if (null === $data) {
                $io->error('Invalid JSON in OCR file.');
                return Command::FAILURE;
            }

            // Handle both single receipt and array of receipts
            $receiptsData = isset($data['merchant']) ? [$data] : $data;

            foreach ($receiptsData as $rData) {
                $receipt = $this->ocrProcessor->processOcrData($rData);
                $io->info(sprintf('Ingested receipt from %s, total %s %s',
                    $receipt->getMerchant(),
                    $receipt->getTotalBgn(),
                    $receipt->getCurrency()
                ));
            }
        }

        $io->section('Linking Receipts to Transactions');
        $linkedTransactions = $this->transactionLinker->linkAllUnmatched();
        $io->success(sprintf('Linked %d receipts to transactions.', $linkedTransactions));

        $io->section('Linking LineItems to Products');
        $linkedProducts = $this->productLinker->linkAllUnlinked();
        $io->success(sprintf('Linked %d line items to products.', $linkedProducts));

        return Command::SUCCESS;
    }
}
