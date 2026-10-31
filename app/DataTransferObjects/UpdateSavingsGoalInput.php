<?php

namespace App\DataTransferObjects;

use App\Models\User;

final class UpdateSavingsGoalInput
{
    /**
     * Name and target are always applied. Nullable fields use explicit clear flags so an
     * omitted (null) description/targetDate does not wipe existing values.
     *
     * @param  mixed  $targetAmountMinor  Must be a positive PHP int in minor units (floats/strings rejected).
     * @param  bool  $clearDescription  When true, description is set to null.
     * @param  bool  $clearTargetDate  When true, target_date is set to null.
     */
    public function __construct(
        public readonly User $user,
        public readonly int $goalId,
        public readonly string $name,
        public readonly mixed $targetAmountMinor,
        public readonly ?string $description = null,
        public readonly bool $clearDescription = false,
        public readonly ?string $targetDate = null,
        public readonly bool $clearTargetDate = false,
    ) {}
}
