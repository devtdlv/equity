<?php

namespace App\DataTransferObjects;

/**
 * Spending versus an explicit category allocation (including an explicit zero limit).
 */
final readonly class BudgetCategorySpending
{
    public function __construct(
        public int $categoryId,
        public string $categoryName,
        public bool $categoryIsArchived,
        public int $allocatedMinor,
        public int $actualMinor,
        public int $remainingMinor,
        public bool $isOverLimit,
    ) {}
}
