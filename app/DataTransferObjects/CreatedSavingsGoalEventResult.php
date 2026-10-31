<?php

namespace App\DataTransferObjects;

use App\Models\SavingsGoalEvent;

final class CreatedSavingsGoalEventResult
{
    public function __construct(
        public readonly SavingsGoalEvent $event,
        public readonly bool $wasRecentlyCreated,
    ) {}
}
