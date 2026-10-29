<?php

namespace App\DataTransferObjects;

use App\Models\Budget;

final readonly class BudgetSpendingSummary
{
    /**
     * @param  list<BudgetCategorySpending>  $allocatedCategories
     */
    public function __construct(
        public Budget $budget,
        public array $allocatedCategories,
        public int $totalAllocatedMinor,
        public int $allocatedActualMinor,
        public int $unbudgetedActualMinor,
        public int $totalActualMinor,
    ) {}
}
