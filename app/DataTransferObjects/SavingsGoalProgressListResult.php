<?php

namespace App\DataTransferObjects;

use Illuminate\Support\Collection;

final class SavingsGoalProgressListResult
{
    /**
     * @param  Collection<int, SavingsGoalProgressSummary>  $summaries
     */
    public function __construct(
        public readonly Collection $summaries,
        public readonly int $total,
        public readonly int $perPage,
        public readonly int $currentPage,
        public readonly int $lastPage,
    ) {}
}
