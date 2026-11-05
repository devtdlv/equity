<?php

namespace App\DataTransferObjects;

use App\Models\FinancialTransaction;
use App\Models\RecurringPaymentLink;

final readonly class PaidRecurringBillResult
{
    public function __construct(
        public FinancialTransaction $transaction,
        public RecurringPaymentLink $paymentLink,
        public OccurrencePaymentSummary $paymentSummary,
        public bool $wasRecentlyCreated,
    ) {}
}
