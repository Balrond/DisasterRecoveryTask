<?php

namespace App\Service;

use App\Entity\Client;
use App\Enum\Tier;

class TierResolver
{
    public function __construct(
        private readonly MonthlyVolumeCalculatorInterface $monthlyVolumeCalculator,
    ) {
    }

    public function resolveTier(Client $client, \DateTimeInterface $date): Tier
    {
        if ($client->isTierLocked() === true) {
            $locked = strtoupper((string) $client->getTierLockedValue());

            return match ($locked) {
                'GOLD' => Tier::GOLD,
                'SILVER' => Tier::SILVER,
                'BRONZE' => Tier::BRONZE,
                default => Tier::BRONZE,
            };
        }

        $currentMonthVolume = $this->monthlyVolumeCalculator->getMonthlyVolumeEur($client, $date);
        $currentTier = $this->tierFromVolume($currentMonthVolume);

        $dayOfMonth = (int) $this->toImmutable($date)->format('j');
        if ($dayOfMonth <= 15) {
            $prevMonthDate = $this->firstDayOfPreviousMonth($date);

            if ($this->monthlyVolumeCalculator->hasMonthlyHistory($client, $prevMonthDate) === false) {
                return $currentTier;
            }

            $prevMonthVolume = $this->monthlyVolumeCalculator->getMonthlyVolumeEur($client, $prevMonthDate);
            $prevTier = $this->tierFromVolume($prevMonthVolume);

            if ($prevTier === Tier::GOLD) {
                return match ($currentTier) {
                    Tier::SILVER => Tier::GOLD,
                    Tier::BRONZE => Tier::BRONZE,
                    default => $currentTier,
                };
            }
        }

        return $currentTier;
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

        $v = (float) $eurVolume;
        if ($v <= 10000.0) {
            return Tier::BRONZE;
        }
        if ($v <= 50000.0) {
            return Tier::SILVER;
        }

        return Tier::GOLD;
    }

    private function firstDayOfPreviousMonth(\DateTimeInterface $date): \DateTimeImmutable
    {
        $d = $this->toImmutable($date);
        $first = $d->modify('first day of this month')->setTime(0, 0, 0);

        return $first->modify('-1 month');
    }

    private function toImmutable(\DateTimeInterface $date): \DateTimeImmutable
    {
        return $date instanceof \DateTimeImmutable
            ? $date
            : \DateTimeImmutable::createFromInterface($date);
    }
}
