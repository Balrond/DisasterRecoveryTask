<?php

namespace App\Service;

use App\Entity\Client;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class MonthlyVolumeCalculator implements MonthlyVolumeCalculatorInterface
{
    /** @var array<string, string> */
    private array $cache = [];

    /** @var array<string, bool> */
    private array $historyCache = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RateLookupService $rateLookup,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getMonthlyVolumeEur(Client $client, \DateTimeInterface $anyDayInMonth): string
    {
        $monthKey = $this->monthKey($client, $anyDayInMonth);
        if (isset($this->cache[$monthKey])) {
            return $this->cache[$monthKey];
        }

        $start = $this->firstDayOfMonth($anyDayInMonth);
        $end = $start->modify('+1 month');

        $sql = <<<SQL
SELECT
  amount,
  source_currency,
  created_at
FROM transactions
WHERE client_id = :client_db_id
  AND created_at >= :start
  AND created_at < :end
  AND (refunded_at IS NULL OR refunded_at > created_at + interval '72 hours')
SQL;

        $rows = $this->em->getConnection()->fetchAllAssociative($sql, [
            'client_db_id' => $client->getId(),
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s'),
        ]);

        // historia miesiąca = istnieje przynajmniej 1 transakcja licząca się do wolumenu
        $this->historyCache[$monthKey] = \count($rows) > 0;

        $sumEur = '0.00';

        foreach ($rows as $r) {
            $amount = (string) ($r['amount'] ?? '0');
            $source = strtoupper((string) ($r['source_currency'] ?? ''));
            $createdAtRaw = (string) ($r['created_at'] ?? '');

            $createdAt = $this->parseDbDateTime($createdAtRaw);
            if (!$createdAt) {
                $this->logger->warning('monthly volume: invalid created_at in DB row', ['created_at' => $createdAtRaw]);
                continue;
            }

            if ($source === 'EUR') {
                $sumEur = $this->addMoney($sumEur, $this->normalizeMoney2($amount));
                continue;
            }

            $rate = $this->rateLookup->getRate($source, 'EUR', $createdAt);
            if ($rate === null) {
                $this->logger->warning('monthly volume: missing FX rate for conversion to EUR', [
                    'client_id' => $client->getClientId(),
                    'source' => $source,
                    'target' => 'EUR',
                    'date' => $createdAt->format('Y-m-d'),
                ]);
                continue;
            }

            $converted = $this->mulAndRoundMoney2($this->normalizeMoney2($amount), $rate);
            $sumEur = $this->addMoney($sumEur, $converted);
        }

        $this->cache[$monthKey] = $sumEur;

        return $sumEur;
    }

    public function hasMonthlyHistory(Client $client, \DateTimeInterface $anyDayInMonth): bool
    {
        $monthKey = $this->monthKey($client, $anyDayInMonth);
        if (\array_key_exists($monthKey, $this->historyCache)) {
            return $this->historyCache[$monthKey];
        }

        $start = $this->firstDayOfMonth($anyDayInMonth);
        $end = $start->modify('+1 month');

        $sql = <<<SQL
SELECT 1
FROM transactions
WHERE client_id = :client_db_id
  AND created_at >= :start
  AND created_at < :end
  AND (refunded_at IS NULL OR refunded_at > created_at + interval '72 hours')
LIMIT 1
SQL;

        $row = $this->em->getConnection()->fetchOne($sql, [
            'client_db_id' => $client->getId(),
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s'),
        ]);

        $this->historyCache[$monthKey] = $row !== false && $row !== null;

        return $this->historyCache[$monthKey];
    }

    private function monthKey(Client $client, \DateTimeInterface $d): string
    {
        $m = $this->toImmutable($d)->format('Y-m');

        return $client->getId() . ':' . $m;
    }

    private function firstDayOfMonth(\DateTimeInterface $d): \DateTimeImmutable
    {
        return $this->toImmutable($d)->modify('first day of this month')->setTime(0, 0, 0);
    }

    private function toImmutable(\DateTimeInterface $d): \DateTimeImmutable
    {
        return $d instanceof \DateTimeImmutable ? $d : \DateTimeImmutable::createFromInterface($d);
    }

    private function parseDbDateTime(string $value): ?\DateTimeImmutable
    {
        $v = trim($value);
        if ($v === '') {
            return null;
        }

        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $v);
        if ($dt) {
            return $dt;
        }

        $cut = preg_replace('/\.\d+$/', '', $v);
        if ($cut && $cut !== $v) {
            $dt2 = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $cut);
            if ($dt2) {
                return $dt2;
            }
        }

        return null;
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

    private function addMoney(string $a, string $b): string
    {
        if (\function_exists('bcadd')) {
            return bcadd($a, $b, 2);
        }

        return number_format(((float) $a) + ((float) $b), 2, '.', '');
    }

    private function mulAndRoundMoney2(string $amount2, string $rate): string
    {
        if (\function_exists('bcmul')) {
            $raw = bcmul($amount2, $rate, 6);

            return $this->roundHalfUp($raw, 2);
        }

        $raw = ((float) $amount2) * ((float) $rate);

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
        $next = (int) ($frac[$scale] ?? '0');

        $roundedFrac = $keep;
        $roundedInt = $int;

        if ($next >= 5) {
            $carry = 1;
            $chars = str_split($keep === '' ? str_repeat('0', $scale) : $keep);
            for ($i = count($chars) - 1; $i >= 0; $i--) {
                $d = (int) $chars[$i] + $carry;
                if ($d >= 10) {
                    $chars[$i] = '0';
                    $carry = 1;
                } else {
                    $chars[$i] = (string) $d;
                    $carry = 0;
                    break;
                }
            }
            $roundedFrac = implode('', $chars);

            if ($carry === 1) {
                if (\function_exists('bcadd')) {
                    $roundedInt = bcadd($roundedInt, '1', 0);
                } else {
                    $roundedInt = (string) (((int) $roundedInt) + 1);
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
