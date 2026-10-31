<?php

namespace App\DataTransferObjects;

use App\Models\SavingsGoal;
use Illuminate\Support\Collection;

final class SavingsGoalListResult
{
    /**
     * @param  Collection<int, SavingsGoal>  $goals
     */
    public function __construct(
        public readonly Collection $goals,
        public readonly int $total,
        public readonly int $perPage,
        public readonly int $currentPage,
        public readonly int $lastPage,
    ) {}
}
