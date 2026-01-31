<?php

namespace App\Dto;

use App\Enum\Tier;

class FeeCalculationResult
{
    public function __construct(
        public readonly string $transactionId,
        public readonly string $clientName,
        public readonly string $amount,
        public readonly string $sourceCurrency,
        public readonly string $targetCurrency,
        public readonly string $rate,             // e.g. "1.08500000"
        public readonly string $converted,        // money 2dp
        public readonly Tier $tier,
        public readonly string $feeRate,          // e.g. "0.0225"
        public readonly string $fee,              // money 2dp
        public readonly string $finalAmount,      // money 2dp
    ) {
    }
}
