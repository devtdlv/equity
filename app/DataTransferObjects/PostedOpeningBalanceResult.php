<?php

namespace App\DataTransferObjects;

use App\Models\FinancialAccount;
use App\Models\JournalEntry;

final readonly class PostedOpeningBalanceResult
{
    public function __construct(
        public FinancialAccount $account,
        public int $amountMinor,
        public bool $wasRecentlyCreated,
        public bool $isNoOp,
        public ?JournalEntry $journalEntry = null,
    ) {}
}
