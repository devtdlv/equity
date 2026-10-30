<?php

namespace App\DataTransferObjects;

use App\Models\RecurringObligation;

final readonly class CreatedRecurringObligationResult
{
    public function __construct(
        public RecurringObligation $obligation,
        public bool $wasRecentlyCreated,
    ) {}
}
