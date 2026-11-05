<?php

namespace App\DataTransferObjects;

use App\Models\User;

/**
 * Read-only query for the Financial Reports module.
 */
final readonly class FinancialReportQuery
{
    public function __construct(
        public User $user,
        public string $mode,
        public string $startDate,
        public string $endDate,
        public ?string $month = null,
        public ?int $financialAccountId = null,
    ) {}
}
