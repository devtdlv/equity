<?php

namespace App\DataTransferObjects;

use App\Enums\RecurringOccurrenceDisplayStatus;
use App\Models\RecurringOccurrence;

final readonly class OccurrencePaymentSummary
{
    /**
     * @param  list<int>  $activeLinkedTransactionIds
     * @param  list<int>  $allLinkedTransactionIds
     */
    public function __construct(
        public RecurringOccurrence $occurrence,
        public string $currencyCode,
        public int $expectedAmountMinor,
        public int $paidAmountMinor,
        public int $remainingAmountMinor,
        public int $overpaidAmountMinor,
        public RecurringOccurrenceDisplayStatus $displayStatus,
        public bool $isPaid,
        public bool $isPartiallyPaid,
        public bool $isOverdue,
        public bool $isDueToday,
        public array $activeLinkedTransactionIds,
        public array $allLinkedTransactionIds,
    ) {}
}
