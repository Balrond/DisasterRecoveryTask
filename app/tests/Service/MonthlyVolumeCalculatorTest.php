<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Client;
use App\Service\MonthlyVolumeCalculator;
use App\Service\RateLookupService;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class MonthlyVolumeCalculatorTest extends TestCase
{
    #[DataProvider('provideMonthlyCases')]
    public function testGetMonthlyVolumeEur(
        string $anyDay,
        array $rows,
        ?string $fxRate,
        string $expectedSum,
        bool $expectedHistory
    ): void {
        $client = new class extends Client {
            public function getId(): ?int { return 999; }
        };
        $client->setClientId('C999');
        $client->setName('Test');

        $conn = $this->createMock(Connection::class);

        $conn->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn($rows);

        $conn->method('fetchOne')->willReturn(\count($rows) > 0 ? 1 : false);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $rateLookup = $this->createStub(RateLookupService::class);
        $rateLookup->method('getRate')->willReturn($fxRate);

        $logger = $this->createStub(LoggerInterface::class);

        $svc = new MonthlyVolumeCalculator($em, $rateLookup, $logger);

        $sum = $svc->getMonthlyVolumeEur($client, new DateTimeImmutable($anyDay));
        $this->assertSame($expectedSum, $sum);

        $hist = $svc->hasMonthlyHistory($client, new DateTimeImmutable($anyDay));
        $this->assertSame($expectedHistory, $hist);
    }

    public static function provideMonthlyCases(): iterable
    {
        yield 'no rows => 0.00 and no history' => [
            '2024-01-10',
            [],
            null,
            '0.00',
            false,
        ];

        yield 'EUR only sums with 2dp normalization' => [
            '2024-01-10',
            [
                ['amount' => '10', 'source_currency' => 'EUR', 'created_at' => '2024-01-05 10:00:00'],
                ['amount' => '2.50', 'source_currency' => 'EUR', 'created_at' => '2024-01-06 11:00:00'],
            ],
            null,
            '12.50',
            true,
        ];

        yield 'missing FX rate skips row (still history true because row exists)' => [
            '2024-01-10',
            [
                ['amount' => '10.00', 'source_currency' => 'USD', 'created_at' => '2024-01-05 10:00:00'],
            ],
            null,
            '0.00',
            true,
        ];

        yield 'invalid created_at skips row (still history true because row exists)' => [
            '2024-01-10',
            [
                ['amount' => '10.00', 'source_currency' => 'EUR', 'created_at' => 'INVALID'],
            ],
            null,
            '0.00',
            true,
        ];

        yield 'non-EUR converted using FX and rounded HALF-UP to 2dp then summed' => [
            '2024-01-10',
            [
                ['amount' => '10.00', 'source_currency' => 'USD', 'created_at' => '2024-01-05 10:00:00'],
            ],
            '0.9219',
            '9.22',
            true,
        ];
    }
}
