<?php

namespace App\DataTransferObjects;

use App\Models\JournalEntry;

final readonly class PostedJournalEntryResult
{
    public function __construct(
        public JournalEntry $journalEntry,
        public bool $wasRecentlyCreated,
    ) {}
}
