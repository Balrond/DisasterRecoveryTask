<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Rate;
use App\Repository\RateRepository;
use App\Service\RateLookupService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RateLookupServiceTest extends TestCase
{
    #[DataProvider('provideRates')]
    public function testRateLookup(
        string $source,
        string $target,
        string $date,
        array $repoRates,
        ?string $expectedExact,
        ?array $expectedApprox
    ): void {
        $repo = new class($repoRates) extends RateRepository {
            public function __construct(private readonly array $rates) {}

            public function findApplicableRate(string $source, string $target, \DateTimeInterface $date): ?Rate
            {
                $key = strtoupper($source) . '|' . strtoupper($target);
                if (!array_key_exists($key, $this->rates)) {
                    return null;
                }

                $r = new Rate();
                $ref = new \ReflectionClass($r);

                if ($ref->hasProperty('rate')) {
                    $p = $ref->getProperty('rate');
                    $p->setAccessible(true);
                    $p->setValue($r, (string) $this->rates[$key]);
                }

                return $r;
            }
        };

        $svc = new RateLookupService($repo);
        $rate = $svc->getRate($source, $target, new \DateTimeImmutable($date));

        if ($expectedExact !== null) {
            self::assertSame($expectedExact, $rate);
            return;
        }

        self::assertNotNull($rate);
        self::assertIsArray($expectedApprox);

        $mulBy = (string) $expectedApprox['mulBy'];
        $expected = (string) $expectedApprox['expected'];
        $epsilon = (float) $expectedApprox['epsilon'];

        $lhs = $this->mul8((string) $rate, $mulBy);
        $this->assertDecimalClose($expected, $lhs, $epsilon);
    }

    public static function provideRates(): iterable
    {
        yield 'same currency' => [
            'EUR',
            'EUR',
            '2024-01-10',
            [],
            '1',
            null,
        ];

        yield 'direct' => [
            'eur',
            'usd',
            '2024-01-20',
            [
                'EUR|USD' => '1.0920',
            ],
            '1.0920',
            null,
        ];

        yield 'inverse' => [
            'EUR',
            'USD',
            '2024-01-10',
            [
                'USD|EUR' => '0.9219',
            ],
            null,
            [
                'mulBy' => '0.9219',
                'expected' => '1.00000000',
                'epsilon' => 1e-7,
            ],
        ];

        yield 'cross via EUR pivot' => [
            'USD',
            'CHF',
            '2024-01-10',
            [
                'EUR|USD' => '1.0850',
                'EUR|CHF' => '0.9340',
            ],
            null,
            [
                'mulBy' => '1.0850',
                'expected' => '0.93400000',
                'epsilon' => 1e-6,
            ],
        ];
    }

    private function mul8(string $a, string $b): string
    {
        if (\function_exists('bcmul')) {
            return bcmul($a, $b, 8);
        }
        return number_format(((float) $a) * ((float) $b), 8, '.', '');
    }

    private function assertDecimalClose(string $expected, string $actual, float $epsilon): void
    {
        $e = (float) $expected;
        $a = (float) $actual;

        self::assertTrue(
            abs($e - $a) <= $epsilon,
            sprintf('Expected %s ~ %s (diff=%s)', $expected, $actual, (string) abs($e - $a))
        );
    }
}
