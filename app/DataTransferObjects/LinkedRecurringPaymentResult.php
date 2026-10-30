<?php

namespace App\DataTransferObjects;

use App\Models\RecurringPaymentLink;

final readonly class LinkedRecurringPaymentResult
{
    public function __construct(
        public RecurringPaymentLink $paymentLink,
        public OccurrencePaymentSummary $paymentSummary,
        public bool $wasRecentlyCreated,
    ) {}
}
