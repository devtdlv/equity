<?php

namespace App\DataTransferObjects;

use App\Models\User;

final readonly class SetBudgetAllocationInput
{
    /**
     * @param  string  $amount  Non-negative decimal string in the budget currency (zero allowed).
     */
    public function __construct(
        public User $user,
        public int $budgetId,
        public int $categoryId,
        public string $amount,
    ) {}
}
