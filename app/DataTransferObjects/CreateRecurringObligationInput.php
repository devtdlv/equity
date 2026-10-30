<?php

namespace App\DataTransferObjects;

use App\Enums\RecurringFrequency;
use App\Models\User;

final readonly class CreateRecurringObligationInput
{
    public function __construct(
        public User $user,
        public string $idempotencyKey,
        public string $name,
        public string $amount,
        public string $currencyCode,
        public int $categoryId,
        public string $startDate,
        public RecurringFrequency|string $frequency,
        public int $anchorDay,
        public bool $endsOfMonth = false,
        public ?string $description = null,
        public ?int $financialAccountId = null,
    ) {}
}
