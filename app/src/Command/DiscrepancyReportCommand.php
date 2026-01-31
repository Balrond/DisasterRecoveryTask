<?php

namespace App\Command;

use App\Repository\TransactionRepository;
use App\Service\FeeCalculator;
use App\Service\MonthlyVolumeCalculatorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:discrepancy-report',
    description: 'Compares calculated fees/finals against original CSV values and prints only discrepancies.'
)]
class DiscrepancyReportCommand extends Command
{
    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly FeeCalculator $feeCalculator,
        private readonly MonthlyVolumeCalculatorInterface $monthlyVolume,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $all = $this->transactions->findAll();
        $discrepancies = [];

        foreach ($all as $tx) {
            if ($tx->getOriginalFee() === null || $tx->getOriginalFinalAmount() === null) {
                continue;
            }

            $origFee = $this->money2((string)$tx->getOriginalFee());
            $origFinal = $this->money2((string)$tx->getOriginalFinalAmount());
            $impliedConverted = $this->addMoney($origFee, $origFinal);
            $impliedFeeRate = $this->safeDiv($origFee, $impliedConverted, 6);

            try {
                $r = $this->feeCalculator->calculate($tx);
            } catch (\Throwable $e) {
                $discrepancies[] = [
                    'id' => $tx->getTransactionId(),
                    'type' => 'error',
                    'message' => $e->getMessage(),
                ];
                continue;
            }

            if ($origFee === $r->fee && $origFinal === $r->finalAmount) {
                continue;
            }

            $classification = $this->classify(
                txDate: $tx->getCreatedAt(),
                clientExternalId: $tx->getClientExternalId(),
                impliedConverted: $impliedConverted,
                ourConverted: $r->converted,
                impliedFeeRate: $impliedFeeRate,
                ourFeeRate: $r->feeRate,
                tx: $tx
            );

            $discrepancies[] = [
                'id' => $tx->getTransactionId(),
                'type' => 'diff',
                'classification' => $classification,
                'tier' => $r->tier->value,
                'orig_fee' => $origFee,
                'calc_fee' => $r->fee,
                'orig_final' => $origFinal,
                'calc_final' => $r->finalAmount,
                'implied_converted' => $impliedConverted,
                'our_converted' => $r->converted,
                'implied_fee_rate' => $impliedFeeRate,
                'our_fee_rate' => $r->feeRate,
            ];
        }

        if (count($discrepancies) === 0) {
            return Command::SUCCESS; // silent
        }

        $output->writeln('Discrepancies found: ' . count($discrepancies));
        $output->writeln('');

        foreach ($discrepancies as $d) {
            $output->writeln($d['id'] . ':');

            if ($d['type'] === 'error') {
                $output->writeln('  Error: ' . $d['message']);
                $output->writeln('');
                continue;
            }

            $output->writeln('  Type: ' . $d['classification'] . ' | Tier: ' . $d['tier']);
            $output->writeln('  Original fee: ' . $d['orig_fee'] . ' | Calculated fee: ' . $d['calc_fee']);
            $output->writeln('  Original final: ' . $d['orig_final'] . ' | Calculated final: ' . $d['calc_final']);
            $output->writeln('  Implied converted: ' . $d['implied_converted'] . ' | Our converted: ' . $d['our_converted']);
            $output->writeln('  Implied feeRate: ' . $d['implied_fee_rate'] . ' | Our feeRate: ' . $d['our_fee_rate']);
            $output->writeln('');
        }

        return Command::SUCCESS;
    }

    private function classify(
        \DateTimeInterface $txDate,
        ?string $clientExternalId,
        string $impliedConverted,
        string $ourConverted,
        string $impliedFeeRate,
        string $ourFeeRate,
        $tx
    ): string {
        if ($this->absDiffMoney($impliedConverted, $ourConverted) > 0.02) {
            return 'RATE_OR_CONVERTED';
        }

        // anomaly feeRate (not close to any known tier)
        if (!$this->isNearAnyKnownFeeRate($impliedFeeRate)) {
            return 'ANOMALY_FEE_RATE';
        }

        // missing history for grace: implied GOLD in first 15 days but we cannot prove prev-month GOLD from available data
        if ((int)$txDate->format('d') <= 15 && $this->isNear($impliedFeeRate, '0.0175', 0.0003) && !$this->isNear($ourFeeRate, '0.0175', 0.0003)) {
            $client = method_exists($tx, 'getClient') ? $tx->getClient() : null;
            if ($client) {
                $prevMonthDate = \DateTimeImmutable::createFromFormat('Y-m-d', $txDate->format('Y-m-01'))?->modify('-1 month')
                    ?: (new \DateTimeImmutable($txDate->format('Y-m-01')))->modify('-1 month');
                $prevVol = $this->monthlyVolume->getMonthlyVolumeEur($client, $prevMonthDate);
                if (\function_exists('bccomp')) {
                    if (bccomp($prevVol, '0', 2) === 0) {
                        return 'MISSING_HISTORY_FOR_GRACE';
                    }
                } else {
                    if ((float)$prevVol === 0.0) {
                        return 'MISSING_HISTORY_FOR_GRACE';
                    }
                }
            }
        }

        if ($this->absDiffRate($impliedFeeRate, $ourFeeRate) > 0.0003) {
            return 'TIER_OR_RULES';
        }

        return 'ROUNDING';
    }

    private function isNearAnyKnownFeeRate(string $r): bool
    {
        return $this->isNear($r, '0.0275', 0.0006)
            || $this->isNear($r, '0.0225', 0.0006)
            || $this->isNear($r, '0.0175', 0.0006);
    }

    private function isNear(string $a, string $b, float $eps): bool
    {
        return abs(((float)$a) - ((float)$b)) <= $eps;
    }

    private function money2(string $value): string
    {
        $v = trim($value);
        if ($v === '') {
            return '0.00';
        }
        if (strpos($v, '.') === false) {
            return $v . '.00';
        }
        [$a, $b] = explode('.', $v, 2);
        $b = substr(str_pad($b, 2, '0'), 0, 2);
        return $a . '.' . $b;
    }

    private function addMoney(string $a, string $b): string
    {
        if (\function_exists('bcadd')) {
            return bcadd($a, $b, 2);
        }
        return number_format(((float)$a) + ((float)$b), 2, '.', '');
    }

    private function safeDiv(string $a, string $b, int $scale): string
    {
        if ($b === '0.00' || $b === '0' || $b === '0.0') {
            return '0';
        }
        if (\function_exists('bcdiv')) {
            return bcdiv($a, $b, $scale);
        }
        return number_format(((float)$a) / ((float)$b), $scale, '.', '');
    }

    private function absDiffMoney(string $a, string $b): float
    {
        return abs(((float)$a) - ((float)$b));
    }

    private function absDiffRate(string $a, string $b): float
    {
        return abs(((float)$a) - ((float)$b));
    }
}
