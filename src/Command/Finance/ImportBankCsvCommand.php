<?php

namespace App\Command\Finance;

use App\Entity\Account;
use App\Service\Finance\BankCsvImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:finance:import',
    description: 'Import bank statement CSV into Joi\'s Eyes DB',
)]
class ImportBankCsvCommand extends Command
{
    public function __construct(
        private BankCsvImporter $importer,
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Path to the CSV file')
            ->addOption('account', 'a', InputOption::VALUE_REQUIRED, 'Account UUID or Name')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filePath = $input->getOption('file');
        $accountRef = $input->getOption('account');

        if (!$filePath || !$accountRef) {
            $io->error('Both --file and --account are required.');
            return Command::FAILURE;
        }

        $account = $this->entityManager->getRepository(Account::class)->find($accountRef);
        if (!$account) {
            $account = $this->entityManager->getRepository(Account::class)->findOneBy(['name' => $accountRef]);
        }

        if (!$account) {
            $io->error(sprintf('Account "%s" not found.', $accountRef));
            return Command::FAILURE;
        }

        $io->note(sprintf('Importing "%s" to account "%s"...', basename($filePath), $account->getName()));

        try {
            $stats = $this->importer->import($filePath, $account);

            $io->success(sprintf(
                'Import complete! Total: %d, Imported: %d, Skipped (duplicates): %d, Errors: %d',
                $stats['total'],
                $stats['imported'],
                $stats['skipped'],
                $stats['errors']
            ));

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}
