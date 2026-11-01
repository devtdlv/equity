<?php

namespace App\DataTransferObjects;

use App\Enums\SavingsGoalStatus;
use Illuminate\Support\Carbon;

final readonly class SavingsGoalProgressSummary
{
    public function __construct(
        public int $goalId,
        public string $name,
        public ?string $description,
        public string $currencyCode,
        public SavingsGoalStatus $status,
        public int $targetAmountMinor,
        public int $allocatedAmountMinor,
        public int $remainingAmountMinor,
        public int $overfundedAmountMinor,
        public int $progressBasisPoints,
        public bool $targetReached,
        public ?string $targetDate,
        public ?Carbon $archivedAt,
        public Carbon $createdAt,
        public Carbon $updatedAt,
    ) {}
}
