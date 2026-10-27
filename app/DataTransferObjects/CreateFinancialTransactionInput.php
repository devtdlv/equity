<?php

namespace App\DataTransferObjects;

use App\Enums\FinancialTransactionType;
use App\Models\User;

final readonly class CreateFinancialTransactionInput
{
    public function __construct(
        public User $user,
        public FinancialTransactionType|string $type,
        public int $financialAccountId,
        public string $amount,
        public string $entryDate,
        public string $description,
        public string $idempotencyKey,
        public ?int $transferFinancialAccountId = null,
        public ?int $categoryId = null,
        public ?string $notes = null,
    ) {}
}
