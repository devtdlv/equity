<?php

namespace App\DataTransferObjects;

final readonly class SavingsGoalCurrencyPortfolioTotals
{
    public function __construct(
        public string $currencyCode,
        public int $activeGoalCount,
        public int $totalTargetMinor,
        public int $totalAllocatedMinor,
        public int $totalRemainingMinor,
        public int $totalOverfundedMinor,
    ) {}
}
