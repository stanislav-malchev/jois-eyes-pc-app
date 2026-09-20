<?php

namespace App\Command\Finance;

use App\Service\Finance\FinanceReportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:finance:report',
    description: 'Generates the Accountability Report (The Game)',
)]
class FinanceReportCommand extends Command
{
    public function __construct(
        private FinanceReportService $reportService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('start', null, InputOption::VALUE_REQUIRED, 'Start date (YYYY-MM-DD)')
            ->addOption('end', null, InputOption::VALUE_REQUIRED, 'End date (YYYY-MM-DD)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $start = $input->getOption('start') ? new \DateTimeImmutable($input->getOption('start')) : null;
        $end = $input->getOption('end') ? new \DateTimeImmutable($input->getOption('end')) : null;

        $report = $this->reportService->generateAccountabilityReport($start, $end);

        $io->title('Accountability Report (The Game)');

        if ($report['period']['start'] || $report['period']['end']) {
            $io->text(sprintf('Period: %s to %s', $report['period']['start'] ?? 'Beginning', $report['period']['end'] ?? 'Now'));
        }

        $table = new Table($output);
        $table->setHeaders(['Metric', 'Value']);
        $table->addRows([
            ['Total Credited (Income)', $report['income'] . ' BGN'],
            ['Total Debited (Spending)', $report['spending'] . ' BGN'],
            ['Internal Moves (Soft-deleted)', $report['internal_moves'] . ' BGN'],
            ['Matched to Receipts', $report['matched_spending'] . ' BGN'],
            ['Unmatched Spending', $report['unmatched_spending'] . ' BGN'],
            ['Match Rate', $report['match_rate_percent'] . '%'],
            ['Products Identified', $report['product_count']],
            ['Total Items Purchased', $report['item_count']],
            ['Cash Variance', $report['variance_bgn'] . ' BGN'],
        ]);
        $table->render();

        if ($report['match_rate_percent'] >= 95) {
            $io->success('Target reached! 95%+ of spending is traced.');
        } elseif ($report['match_rate_percent'] >= 75) {
            $io->warning('Getting there. 75%+ traced.');
        } else {
            $io->error('Low trace rate. More receipts needed.');
        }

        return Command::SUCCESS;
    }
}
