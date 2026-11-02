<?php

namespace App\DataTransferObjects;

use App\Models\FinancialAccount;

final class CreatedFinancialAccountResult
{
    public function __construct(
        public readonly FinancialAccount $account,
        public readonly bool $wasRecentlyCreated,
    ) {}
}
