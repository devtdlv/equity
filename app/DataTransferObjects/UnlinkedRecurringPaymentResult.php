<?php

namespace App\DataTransferObjects;

final readonly class UnlinkedRecurringPaymentResult
{
    public function __construct(
        public int $occurrenceId,
        public int $financialTransactionId,
        public OccurrencePaymentSummary $paymentSummary,
    ) {}
}
