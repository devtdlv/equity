<?php

namespace App\DataTransferObjects;

use App\Models\User;

final readonly class PayRecurringBillInput
{
    public function __construct(
        public User $user,
        public int $occurrenceId,
        public int $financialAccountId,
        public string $amount,
        public string $idempotencyKey,
        public string $entryDate,
        public ?string $notes = null,
    ) {}
}
