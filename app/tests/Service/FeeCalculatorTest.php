<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\FeeCalculationResult;
use App\Entity\Client;
use App\Entity\Transaction;
use App\Enum\Tier;
use App\Service\FeeCalculator;
use App\Service\RateLookupService;
use App\Service\TierResolver;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class FeeCalculatorTest extends TestCase
{
    #[DataProvider('provideFeeCases')]
    public function testCalculate(
        string $amount,
        string $source,
        string $target,
        string $rate,
        Tier $tier,
        string $expectedConverted,
        string $expectedFee,
        string $expectedFinal
    ): void {
        $rateLookup = $this->createStub(RateLookupService::class);
        $rateLookup->method('getRate')->willReturn($rate);

        $tierResolver = $this->createStub(TierResolver::class);
        $tierResolver->method('resolveTier')->willReturn($tier);

        $logger = $this->createStub(LoggerInterface::class);

        $client = new Client();
        $client->setName('Test Client');

        $tx = new Transaction();
        $tx->setTransactionId('TTEST');
        $tx->setClient($client);
        $tx->setAmount($amount);
        $tx->setSourceCurrency($source);
        $tx->setTargetCurrency($target);
        $tx->setCreatedAt(new DateTimeImmutable('2024-01-10 12:00:00'));

        $calc = new FeeCalculator($rateLookup, $tierResolver, $logger);
        $res = $calc->calculate($tx);

        $this->assertInstanceOf(FeeCalculationResult::class, $res);
        $this->assertSame($expectedConverted, $res->converted);
        $this->assertSame($expectedFee, $res->fee);
        $this->assertSame($expectedFinal, $res->finalAmount);
    }

    public static function provideFeeCases(): iterable
    {
        yield 'happy path SILVER 2.25%' => [
            '100.00', 'EUR', 'USD',
            '1.0850',
            Tier::SILVER,
            '108.50',
            '2.44',
            '106.06',
        ];

        yield 'CHF floor: BRONZE -> SILVER minimum when CHF involved' => [
            '100.00', 'GBP', 'CHF',
            '1.0875',
            Tier::BRONZE, 
            '108.75',
            '2.45',
            '106.30',
        ];

        yield 'GOLD 1.75%' => [
            '100.00', 'EUR', 'USD',
            '1.0850',
            Tier::GOLD,
            '108.50',
            '1.90',
            '106.60',
        ];
    }
}
