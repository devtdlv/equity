<?php

namespace App\DataTransferObjects;

use App\Models\User;

final readonly class PostOpeningBalanceInput
{
    public function __construct(
        public User $user,
        public int $financialAccountId,
        public int $amountMinor,
        public string $entryDate,
        public string $idempotencyKey,
    ) {}
}
