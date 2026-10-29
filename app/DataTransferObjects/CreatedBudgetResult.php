<?php

namespace App\DataTransferObjects;

use App\Models\Budget;

final readonly class CreatedBudgetResult
{
    public function __construct(
        public Budget $budget,
        public bool $wasRecentlyCreated,
    ) {}
}
