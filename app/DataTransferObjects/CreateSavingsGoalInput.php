<?php

namespace App\DataTransferObjects;

use App\Models\User;

final class CreateSavingsGoalInput
{
    /**
     * @param  mixed  $targetAmountMinor  Must be a positive PHP int in minor units (floats/strings rejected).
     */
    public function __construct(
        public readonly User $user,
        public readonly string $idempotencyKey,
        public readonly string $name,
        public readonly string $currencyCode,
        public readonly mixed $targetAmountMinor,
        public readonly ?string $description = null,
        public readonly ?string $targetDate = null,
    ) {}
}
