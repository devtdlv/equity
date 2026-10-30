<?php

namespace App\DataTransferObjects;

use App\Enums\RecurringFrequency;
use App\Models\User;

final readonly class UpdateRecurringObligationInput
{
    public function __construct(
        public User $user,
        public int $obligationId,
        public string $name,
        public string $amount,
        public int $categoryId,
        public string $startDate,
        public RecurringFrequency|string $frequency,
        public int $anchorDay,
        public bool $endsOfMonth = false,
        public ?string $description = null,
        public ?int $financialAccountId = null,
    ) {}
}
