<?php

namespace App\DataTransferObjects;

use App\Models\User;

final readonly class PostJournalEntryInput
{
    /**
     * @param  list<PostingInstruction>  $postings
     */
    public function __construct(
        public User $user,
        public string $currencyCode,
        public string $entryDate,
        public string $description,
        public string $idempotencyKey,
        public array $postings,
        public ?string $externalReference = null,
    ) {}
}
