<?php

namespace App\Command;

use App\Repository\TransactionRepository;
use App\Service\FeeCalculator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:calculate-fee',
    description: 'Calculates and displays fee details for a given transaction_id'
)]
class CalculateFeeCommand extends Command
{
    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly FeeCalculator $feeCalculator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('transaction_id', InputArgument::REQUIRED, 'Transaction ID (e.g. T0001)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $txId = (string)$input->getArgument('transaction_id');

        $tx = $this->transactions->findOneByTransactionId($txId);
        if (!$tx) {
            $output->writeln("<error>Transaction not found: {$txId}</error>");
            return Command::FAILURE;
        }

        try {
            $r = $this->feeCalculator->calculate($tx);
        } catch (\Throwable $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        $output->writeln("Transaction: {$r->transactionId}");
        $output->writeln("Client: {$r->clientName}");
        $output->writeln("Amount: {$r->amount} {$r->sourceCurrency} \u{2192} {$r->targetCurrency}");
        $output->writeln("Rate: {$r->rate}");
        $output->writeln("Converted: {$r->converted}");
        $output->writeln("Tier: {$r->tier->value}");
        $output->writeln("Fee: {$r->fee}");
        $output->writeln("Final: {$r->finalAmount}");

        return Command::SUCCESS;
    }
}
