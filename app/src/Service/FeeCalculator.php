<?php

namespace App\Service;

use App\Dto\FeeCalculationResult;
use App\Entity\Transaction;
use App\Enum\Tier;
use Psr\Log\LoggerInterface;

class FeeCalculator
{
    private const FEE_BRONZE = '0.0275';
    private const FEE_SILVER = '0.0225';
    private const FEE_GOLD   = '0.0175';

    public function __construct(
        private readonly RateLookupService $rateLookup,
        private readonly TierResolver $tierResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function calculate(Transaction $tx): FeeCalculationResult
    {
        $client = $tx->getClient();
        $clientName = $client?->getName() ?? '(unknown client)';

        $source = strtoupper($tx->getSourceCurrency());
        $target = strtoupper($tx->getTargetCurrency());

        $rate = $this->rateLookup->getRate($source, $target, $tx->getCreatedAt());
        if ($rate === null) {
            $this->logger->error('fee calc: missing FX rate', [
                'transaction_id' => $tx->getTransactionId(),
                'source' => $source,
                'target' => $target,
                'date' => $tx->getCreatedAt()->format('Y-m-d'),
            ]);
            throw new \RuntimeException("Rate not found for {$source} -> {$target} at " . $tx->getCreatedAt()->format('Y-m-d'));
        }

        // kurs do 4dp (jak stary system)
        $rateForConversion = $this->roundRate($rate, 4);

        $amount = $this->normalizeMoney2($tx->getAmount());
        $converted = $this->mulAndRoundMoney2($amount, $rateForConversion);

        $tier = Tier::BRONZE;
        if ($client) {
            $tier = $this->tierResolver->resolveTier($client, $tx->getCreatedAt());
        } else {
            $this->logger->warning('fee calc: transaction has no client relation, defaulting tier to BRONZE', [
                'transaction_id' => $tx->getTransactionId(),
                'client_external_id' => $tx->getClientExternalId(),
            ]);
        }

        if ($source === 'CHF' || $target === 'CHF') {
            if ($tier === Tier::BRONZE) {
                $tier = Tier::SILVER;
            }
        }

        $feeRate = match ($tier) {
            Tier::BRONZE => self::FEE_BRONZE,
            Tier::SILVER => self::FEE_SILVER,
            Tier::GOLD => self::FEE_GOLD,
        };

        // fee od converted(2dp) — jak wcześniej
        $fee = $this->mulAndRoundMoney2($converted, $feeRate);
        $final = $this->subMoney($converted, $fee);

        return new FeeCalculationResult(
            transactionId: $tx->getTransactionId(),
            clientName: $clientName,
            amount: $amount,
            sourceCurrency: $source,
            targetCurrency: $target,
            rate: $rateForConversion,
            converted: $converted,
            tier: $tier,
            feeRate: $feeRate,
            fee: $fee,
            finalAmount: $final,
        );
    }

    private function roundRate(string $rate, int $scale): string
    {
        return number_format(round((float)$rate, $scale), $scale, '.', '');
    }

    private function normalizeMoney2(string $value): string
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

    private function subMoney(string $a, string $b): string
    {
        if (\function_exists('bcsub')) {
            return bcsub($a, $b, 2);
        }
        return number_format(((float)$a) - ((float)$b), 2, '.', '');
    }

    private function mulAndRoundMoney2(string $amount2, string $rate): string
    {
        if (\function_exists('bcmul')) {
            $raw = bcmul($amount2, $rate, 6);
            return $this->roundHalfUp($raw, 2);
        }

        $raw = ((float)$amount2) * ((float)$rate);
        return number_format(round($raw, 2), 2, '.', '');
    }

    private function roundHalfUp(string $value, int $scale): string
    {
        $v = trim($value);
        if ($v === '') {
            return '0.00';
        }

        if (strpos($v, '.') === false) {
            return $v . '.' . str_repeat('0', $scale);
        }

        [$int, $frac] = explode('.', $v, 2);
        $frac = preg_replace('/\D/', '', $frac) ?? '';
        $frac = str_pad($frac, $scale + 1, '0');

        $keep = substr($frac, 0, $scale);
        $next = (int)($frac[$scale] ?? '0');

        $roundedInt = $int;
        $roundedFrac = $keep;

        if ($next >= 5) {
            $carry = 1;
            $chars = str_split($keep === '' ? str_repeat('0', $scale) : $keep);
            for ($i = count($chars) - 1; $i >= 0; $i--) {
                $d = (int)$chars[$i] + $carry;
                if ($d >= 10) {
                    $chars[$i] = '0';
                    $carry = 1;
                } else {
                    $chars[$i] = (string)$d;
                    $carry = 0;
                    break;
                }
            }
            $roundedFrac = implode('', $chars);

            if ($carry === 1) {
                if (\function_exists('bcadd')) {
                    $roundedInt = bcadd($roundedInt, '1', 0);
                } else {
                    $roundedInt = (string)(((int)$roundedInt) + 1);
                }
            }
        }

        if ($scale === 0) {
            return $roundedInt;
        }

        $roundedFrac = str_pad($roundedFrac, $scale, '0');
        return $roundedInt . '.' . $roundedFrac;
    }
}
