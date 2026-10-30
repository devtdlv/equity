<?php

namespace App\DataTransferObjects;

/**
 * Read-only payment-link projection for authenticated occurrence responses.
 */
final readonly class RecurringPaymentLinkSummary
{
    public function __construct(
        public int $id,
        public int $occurrenceId,
        public int $financialTransactionId,
        public int $amountMinor,
        public string $currencyCode,
        public bool $isActive,
        public bool $isReversed,
    ) {}
}
