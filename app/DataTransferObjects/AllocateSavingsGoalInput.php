<?php

namespace App\DataTransferObjects;

use App\Models\User;

final class AllocateSavingsGoalInput
{
    /**
     * @param  mixed  $amountMinor  Must be a positive PHP int in minor units (floats/strings rejected).
     */
    public function __construct(
        public readonly User $user,
        public readonly int $goalId,
        public readonly string $idempotencyKey,
        public readonly mixed $amountMinor,
        public readonly ?string $note = null,
    ) {}
}
