<?php

namespace App\Command;

use App\Entity\Client;
use App\Enum\Tier;
use App\Repository\ClientRepository;
use App\Service\MonthlyVolumeCalculatorInterface;
use App\Service\TierResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:debug-tier',
    description: 'Debug monthly volume + tier resolution for a client at a given date'
)]
class DebugTierCommand extends Command
{
    public function __construct(
        private readonly ClientRepository $clients,
        private readonly MonthlyVolumeCalculatorInterface $monthlyVolume,
        private readonly TierResolver $tierResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('client_id', InputArgument::REQUIRED, 'Client external id (e.g. C009)')
            ->addArgument('date', InputArgument::REQUIRED, 'Date (YYYY-MM-DD)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $clientId = (string)$input->getArgument('client_id');
        $dateRaw = (string)$input->getArgument('date');

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $dateRaw);
        if (!$date) {
            $output->writeln('<error>Invalid date format, expected YYYY-MM-DD</error>');
            return Command::FAILURE;
        }

        /** @var Client|null $client */
        $client = $this->clients->findOneByClientId($clientId);
        if (!$client) {
            $output->writeln("<error>Client not found: {$clientId}</error>");
            return Command::FAILURE;
        }

        $currentVol = $this->monthlyVolume->getMonthlyVolumeEur($client, $date);
        $currentTier = $this->tierFromVolume($currentVol);

        $prevMonthDate = $date->modify('first day of this month')->modify('-1 month');
        $prevVol = $this->monthlyVolume->getMonthlyVolumeEur($client, $prevMonthDate);
        $prevTier = $this->tierFromVolume($prevVol);

        $resolved = $this->tierResolver->resolveTier($client, $date);

        $output->writeln("Client: {$client->getName()} ({$client->getClientId()})");
        $output->writeln("Date: {$date->format('Y-m-d')}");
        $output->writeln("Locked tier: " . ($client->isTierLocked() === true ? ($client->getTierLockedValue() ?? 'true') : 'no'));
        $output->writeln("");
        $output->writeln("Current month volume (EUR): {$currentVol} => tier by volume: {$currentTier->value}");
        $output->writeln("Prev month volume (EUR): {$prevVol} => tier by volume: {$prevTier->value}");
        $output->writeln("");
        $output->writeln("Resolved tier (with grace): {$resolved->value}");

        return Command::SUCCESS;
    }

    private function tierFromVolume(string $eurVolume): Tier
    {
        if (\function_exists('bccomp')) {
            if (bccomp($eurVolume, '10000', 2) <= 0) {
                return Tier::BRONZE;
            }
            if (bccomp($eurVolume, '50000', 2) <= 0) {
                return Tier::SILVER;
            }
            return Tier::GOLD;
        }

        $v = (float)$eurVolume;
        if ($v <= 10000.0) {
            return Tier::BRONZE;
        }
        if ($v <= 50000.0) {
            return Tier::SILVER;
        }
        return Tier::GOLD;
    }
}
