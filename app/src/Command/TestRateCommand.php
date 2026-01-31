<?php

namespace App\Command;

use App\Service\RateLookupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:test-rate',
    description: 'Test currency rate lookup for given source, target and date'
)]
class TestRateCommand extends Command
{
    public function __construct(
        private readonly RateLookupService $rateLookup
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('source', InputArgument::REQUIRED, 'Source currency (e.g. EUR)')
            ->addArgument('target', InputArgument::REQUIRED, 'Target currency (e.g. USD)')
            ->addArgument('date', InputArgument::REQUIRED, 'Date (YYYY-MM-DD)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $source = (string)$input->getArgument('source');
        $target = (string)$input->getArgument('target');
        $dateRaw = (string)$input->getArgument('date');

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $dateRaw);
        if (!$date) {
            $output->writeln("<error>Invalid date format, expected YYYY-MM-DD</error>");
            return Command::FAILURE;
        }

        $rate = $this->rateLookup->getRate($source, $target, $date);

        if ($rate === null) {
            $output->writeln("Rate not found for {$source} → {$target} at {$dateRaw}");
            return Command::SUCCESS;
        }

        $output->writeln("Rate {$source} → {$target} at {$dateRaw}: {$rate}");
        return Command::SUCCESS;
    }
}
