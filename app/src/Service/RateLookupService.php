<?php

namespace App\Service;

use App\Repository\RateRepository;

class RateLookupService
{
    public function __construct(private readonly RateRepository $rates)
    {
    }

    /**
     * Returns applicable rate for given date (latest valid_from <= date).
     * - direct pair
     * - inverse pair (1/rate)
     * - cross via pivot currency (EUR, then USD, then CHF)
     */
    public function getRate(string $source, string $target, \DateTimeInterface $date): ?string
    {
        $source = strtoupper(trim($source));
        $target = strtoupper(trim($target));
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $date->format('Y-m-d'))
        ?: ($date instanceof \DateTimeImmutable ? $date : \DateTimeImmutable::createFromInterface($date));
    
        if ($source === '' || $target === '') {
            return null;
        }

        if ($source === $target) {
            return '1';
        }

        // 1) direct
        $direct = $this->rates->findApplicableRate($source, $target, $date);
        if ($direct) {
            return (string)$direct->getRate();
        }

        // 2) inverse
        $inv = $this->rates->findApplicableRate($target, $source, $date);
        if ($inv) {
            $r = (string)$inv->getRate();
            $invRate = $this->invertRate($r);
            if ($invRate !== null) {
                return $invRate;
            }
        }

        // 3) cross via pivot(s)
        foreach (['EUR', 'USD', 'CHF'] as $pivot) {
            if ($pivot === $source || $pivot === $target) {
                continue;
            }

            $a = $this->getDirectOrInverse($source, $pivot, $date);
            if ($a === null) {
                continue;
            }

            $b = $this->getDirectOrInverse($pivot, $target, $date);
            if ($b === null) {
                continue;
            }

            // rate = a * b
            return $this->mulRate($a, $b, 8);
        }

        return null;
    }

    private function getDirectOrInverse(string $source, string $target, \DateTimeInterface $date): ?string
    {
        if ($source === $target) {
            return '1';
        }

        $direct = $this->rates->findApplicableRate($source, $target, $date);
        if ($direct) {
            return (string)$direct->getRate();
        }

        $inv = $this->rates->findApplicableRate($target, $source, $date);
        if ($inv) {
            return $this->invertRate((string)$inv->getRate());
        }

        return null;
    }

    private function invertRate(string $rate): ?string
    {
        $r = trim($rate);
        if (!$this->isPositiveDecimal($r)) {
            return null;
        }

        if (\function_exists('bccomp') && \function_exists('bcdiv')) {
            if (bccomp($r, '0', 10) !== 1) {
                return null;
            }
            return bcdiv('1', $r, 8);
        }

        $rf = (float)$r;
        if ($rf <= 0.0) {
            return null;
        }
        return number_format(1.0 / $rf, 8, '.', '');
    }

    private function mulRate(string $a, string $b, int $scale): string
    {
        if (\function_exists('bcmul')) {
            return bcmul($a, $b, $scale);
        }
        return number_format(((float)$a) * ((float)$b), $scale, '.', '');
    }

    private function isPositiveDecimal(string $value): bool
    {
        if (!preg_match('/^\d+(\.\d+)?$/', $value)) {
            return false;
        }
        if (\function_exists('bccomp')) {
            return bccomp($value, '0', 10) === 1;
        }
        return (float)$value > 0.0;
    }
}
