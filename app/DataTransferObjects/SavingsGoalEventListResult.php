<?php

namespace App\DataTransferObjects;

use App\Models\SavingsGoalEvent;
use Illuminate\Support\Collection;

final class SavingsGoalEventListResult
{
    /**
     * @param  Collection<int, SavingsGoalEvent>  $events
     */
    public function __construct(
        public readonly Collection $events,
        public readonly int $total,
        public readonly int $perPage,
        public readonly int $currentPage,
        public readonly int $lastPage,
    ) {}
}
