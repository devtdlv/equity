<?php

namespace App\DataTransferObjects;

use App\Models\SavingsGoal;

final class CreatedSavingsGoalResult
{
    public function __construct(
        public readonly SavingsGoal $goal,
        public readonly bool $wasRecentlyCreated,
    ) {}
}
