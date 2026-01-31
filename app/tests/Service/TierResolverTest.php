<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Client;
use App\Enum\Tier;
use App\Service\MonthlyVolumeCalculatorInterface;
use App\Service\TierResolver;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TierResolverTest extends TestCase
{
    #[DataProvider('provideTiers')]
    public function testResolveTier(
        Client $client,
        string $date,
        string $curVol,
        bool $prevHasHistory,
        string $prevVol,
        Tier $expected
    ): void {
        $dateObj = new DateTimeImmutable($date);
        $currentMonth = $dateObj->format('Y-m');
        $prevMonth = $dateObj->modify('first day of last month')->format('Y-m');

        $mvc = $this->createStub(MonthlyVolumeCalculatorInterface::class);

        $mvc->method('getMonthlyVolumeEur')->willReturnCallback(
            function (Client $c, \DateTimeInterface $d) use ($currentMonth, $prevMonth, $curVol, $prevVol): string {
                $m = (new DateTimeImmutable($d->format('Y-m-d')))->format('Y-m');
                if ($m === $currentMonth) {
                    return $curVol;
                }
                if ($m === $prevMonth) {
                    return $prevVol;
                }
                return '0.00';
            }
        );

        $mvc->method('hasMonthlyHistory')->willReturn($prevHasHistory);

        $resolver = new TierResolver($mvc);
        $out = $resolver->resolveTier($client, $dateObj);

        $this->assertSame($expected, $out);
    }

    public static function provideTiers(): iterable
    {
        $lockedGold = new Client();
        $lockedGold->setName('Locked');
        $lockedGold->setTierLocked(true);
        $lockedGold->setTierLockedValue('GOLD');

        yield 'locked GOLD ignores volume and grace' => [
            $lockedGold,
            '2024-01-10',
            '0.00',
            false,
            '0.00',
            Tier::GOLD,
        ];

        $c = new Client();
        $c->setName('Normal');
        $c->setTierLocked(false);

        yield 'no grace after 15th' => [
            $c,
            '2024-01-16',
            '12000.00',   // current SILVER
            true,
            '60000.00',   // prev GOLD
            Tier::SILVER,
        ];

        yield 'no grace when previous month has no history' => [
            $c,
            '2024-01-10',
            '12000.00',
            false,
            '60000.00',
            Tier::SILVER,
        ];

        yield 'grace applies: prev GOLD -> current SILVER => GOLD for day<=15' => [
            $c,
            '2024-01-10',
            '12000.00',   // SILVER
            true,
            '60000.00',   // prev GOLD
            Tier::GOLD,
        ];

        yield 'grace exception: prev GOLD -> current BRONZE stays BRONZE' => [
            $c,
            '2024-01-10',
            '100.00',     // BRONZE
            true,
            '60000.00',   // prev GOLD
            Tier::BRONZE,
        ];
    }
}
