<?php

namespace App\DataTransferObjects;

use App\Models\User;

final readonly class LinkRecurringPaymentInput
{
    public function __construct(
        public User $user,
        public int $occurrenceId,
        public int $financialTransactionId,
    ) {}
}
