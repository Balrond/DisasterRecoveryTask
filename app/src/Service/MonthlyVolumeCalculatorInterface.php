<?php

namespace App\Service;

use App\Entity\Client;

interface MonthlyVolumeCalculatorInterface
{
    public function getMonthlyVolumeEur(Client $client, \DateTimeInterface $anyDayInMonth): string;
    public function hasMonthlyHistory(Client $client, \DateTimeInterface $anyDayInMonth): bool;
}
