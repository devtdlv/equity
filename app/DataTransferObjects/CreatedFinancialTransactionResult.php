<?php

namespace App\DataTransferObjects;

use App\Models\FinancialTransaction;
use App\Models\JournalEntry;

final readonly class CreatedFinancialTransactionResult
{
    public function __construct(
        public FinancialTransaction $transaction,
        public JournalEntry $journalEntry,
        public bool $wasRecentlyCreated,
    ) {}
}
