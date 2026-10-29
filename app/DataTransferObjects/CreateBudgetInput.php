<?php

namespace App\DataTransferObjects;

use App\Models\User;

final readonly class CreateBudgetInput
{
    public function __construct(
        public User $user,
        public string $currencyCode,
        public int $year,
        public int $month,
    ) {}
}
