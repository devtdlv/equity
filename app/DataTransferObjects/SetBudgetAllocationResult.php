<?php

namespace App\DataTransferObjects;

use App\Models\BudgetAllocation;

final readonly class SetBudgetAllocationResult
{
    public function __construct(
        public BudgetAllocation $allocation,
        public ?int $previousAmountMinor,
        public bool $wasRecentlyCreated,
    ) {}
}
