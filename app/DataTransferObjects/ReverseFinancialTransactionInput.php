<?php

namespace App\DataTransferObjects;

use App\Models\User;

final readonly class ReverseFinancialTransactionInput
{
    public function __construct(
        public User $user,
        public int $originalTransactionId,
        public string $idempotencyKey,
        public ?string $reason = null,
    ) {}
}
