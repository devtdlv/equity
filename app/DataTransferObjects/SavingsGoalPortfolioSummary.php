<?php

namespace App\DataTransferObjects;

final readonly class SavingsGoalPortfolioSummary
{
    /**
     * @param  list<SavingsGoalCurrencyPortfolioTotals>  $currencies
     */
    public function __construct(
        public int $totalGoalCount,
        public int $activeGoalCount,
        public int $archivedGoalCount,
        public int $activeAtTargetCount,
        public int $activeBelowTargetCount,
        public array $currencies,
    ) {}
}
