<?php

namespace App\DataTransferObjects;

final readonly class PostingInstruction
{
    public function __construct(
        public int $ledgerAccountId,
        public int $debitAmountMinor = 0,
        public int $creditAmountMinor = 0,
    ) {}
}
