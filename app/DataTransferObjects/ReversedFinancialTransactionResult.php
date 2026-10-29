<?php

namespace App\DataTransferObjects;

use App\Models\FinancialTransaction;
use App\Models\JournalEntry;

final readonly class ReversedFinancialTransactionResult
{
    public function __construct(
        public FinancialTransaction $reversalTransaction,
        public FinancialTransaction $originalTransaction,
        public JournalEntry $reversalJournalEntry,
        public bool $wasRecentlyCreated,
    ) {}
}
