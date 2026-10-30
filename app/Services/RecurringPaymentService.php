<?php

namespace App\Services;

use App\DataTransferObjects\LinkedRecurringPaymentResult;
use App\DataTransferObjects\LinkRecurringPaymentInput;
use App\DataTransferObjects\OccurrencePaymentSummary;
use App\DataTransferObjects\RecurringPaymentLinkSummary;
use App\DataTransferObjects\UnlinkedRecurringPaymentResult;
use App\DataTransferObjects\UnlinkRecurringPaymentInput;
use App\Enums\FinancialTransactionType;
use App\Enums\RecurringObligationStatus;
use App\Enums\RecurringOccurrenceDisplayStatus;
use App\Enums\RecurringOccurrenceStatus;
use App\Exceptions\LedgerIntegrityException;
use App\Models\FinancialTransaction;
use App\Models\RecurringObligation;
use App\Models\RecurringOccurrence;
use App\Models\RecurringPaymentLink;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class RecurringPaymentService
{
    public const TRANSACTION_LINK_UNIQUE_INDEX = 'recurring_payment_links_financial_transaction_id_unique';

    public const OCCURRENCE_TRANSACTION_UNIQUE_INDEX = 'recurring_payment_links_occurrence_transaction_unique';

    public const MAX_CONCURRENCY_ATTEMPTS = 3;

    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    /**
     * Links an already-posted active expense transaction to a scheduled occurrence.
     *
     * Does not create or mutate financial transactions. Same occurrence + same transaction
     * retries return the existing link (idempotent replay).
     */
    public function linkPayment(LinkRecurringPaymentInput $input): LinkedRecurringPaymentResult
    {
        $this->assertValidUser($input->user);

        if ($input->occurrenceId <= 0 || $input->financialTransactionId <= 0) {
            throw new LedgerIntegrityException(
                'Occurrence and financial transaction identifiers must be positive integers.',
            );
        }

        for ($attempt = 1; $attempt <= self::MAX_CONCURRENCY_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($input): LinkedRecurringPaymentResult {
                    [$obligation, $occurrence] = $this->lockObligationAndOccurrence(
                        $input->user,
                        $input->occurrenceId,
                    );

                    $this->assertOccurrenceAcceptsPaymentLinks($obligation, $occurrence);

                    $transaction = $this->lockOwnedTransaction(
                        $input->user,
                        $input->financialTransactionId,
                    );

                    $existingForTransaction = RecurringPaymentLink::query()
                        ->where('financial_transaction_id', $transaction->id)
                        ->lockForUpdate()
                        ->first();

                    if ($existingForTransaction !== null) {
                        if ((int) $existingForTransaction->occurrence_id === (int) $occurrence->id
                            && (int) $existingForTransaction->user_id === (int) $input->user->id
                        ) {
                            $summary = $this->buildPaymentSummary($input->user, $occurrence);

                            return new LinkedRecurringPaymentResult(
                                paymentLink: $existingForTransaction->fresh(['financialTransaction', 'occurrence'])
                                    ?? $existingForTransaction,
                                paymentSummary: $summary,
                                wasRecentlyCreated: false,
                            );
                        }

                        throw new LedgerIntegrityException(
                            'This financial transaction is already linked to another recurring occurrence.',
                        );
                    }

                    $this->assertTransactionEligibleForLink($transaction, $obligation, $occurrence);

                    $previousPaid = $this->sumActivePaidAmountMinor($occurrence);
                    // Reject links that would overflow checked paid totals.
                    Money::checkedAdd($previousPaid, (int) $transaction->amount_minor);

                    $link = RecurringPaymentLink::query()->create([
                        'user_id' => $input->user->id,
                        'occurrence_id' => $occurrence->id,
                        'financial_transaction_id' => $transaction->id,
                        'currency_code' => $occurrence->currency_code,
                    ]);

                    $summary = $this->buildPaymentSummary($input->user, $occurrence);

                    $this->auditService->record(
                        actor: $input->user,
                        action: AuditService::ACTION_RECURRING_PAYMENT_LINKED,
                        auditableType: RecurringPaymentLink::class,
                        auditableId: (int) $link->id,
                        metadata: [
                            'obligation_id' => (int) $obligation->id,
                            'occurrence_id' => (int) $occurrence->id,
                            'financial_transaction_id' => (int) $transaction->id,
                            'currency_code' => $occurrence->currency_code,
                            'transaction_amount_minor' => (int) $transaction->amount_minor,
                            'previous_paid_amount_minor' => $previousPaid,
                            'new_paid_amount_minor' => $summary->paidAmountMinor,
                            'expected_amount_minor' => $summary->expectedAmountMinor,
                            'display_status' => $summary->displayStatus->value,
                        ],
                    );

                    return new LinkedRecurringPaymentResult(
                        paymentLink: $link->fresh(['financialTransaction', 'occurrence']) ?? $link,
                        paymentSummary: $summary,
                        wasRecentlyCreated: true,
                    );
                });
            } catch (UniqueConstraintViolationException $exception) {
                $recovered = $this->recoverDuplicateLinkAttempt($input, $exception);
                if ($recovered !== null) {
                    return $recovered;
                }

                if ($attempt < self::MAX_CONCURRENCY_ATTEMPTS && $this->isRetryableLinkUniqueViolation($exception)) {
                    continue;
                }

                throw $this->mapLinkUniqueViolation($exception, $input);
            } catch (QueryException $exception) {
                if ($this->isTransactionLinkUniqueViolation($exception)
                    || $this->isOccurrenceTransactionUniqueViolation($exception)
                ) {
                    $recovered = $this->recoverDuplicateLinkAttempt($input, $exception);
                    if ($recovered !== null) {
                        return $recovered;
                    }

                    if ($attempt < self::MAX_CONCURRENCY_ATTEMPTS) {
                        continue;
                    }

                    throw $this->mapLinkUniqueViolation($exception, $input);
                }

                throw $exception;
            }
        }

        throw new LedgerIntegrityException(
            'Unable to link recurring payment due to concurrent contention.',
        );
    }

    /**
     * Removes a payment association without mutating the financial transaction.
     *
     * Durable unlink history is recorded via AuditEvent (recurring_payment.unlinked).
     * The live link row is deleted so the transaction unique constraint can accept a
     * future re-link; soft-delete would require a schema change and is not present.
     */
    public function unlinkPayment(UnlinkRecurringPaymentInput $input): UnlinkedRecurringPaymentResult
    {
        $this->assertValidUser($input->user);

        if ($input->occurrenceId <= 0 || $input->financialTransactionId <= 0) {
            throw new LedgerIntegrityException(
                'Occurrence and financial transaction identifiers must be positive integers.',
            );
        }

        return DB::transaction(function () use ($input): UnlinkedRecurringPaymentResult {
            [$obligation, $occurrence] = $this->lockObligationAndOccurrence(
                $input->user,
                $input->occurrenceId,
            );

            // Lock candidate transaction row (ascending ID order with any other locks: already locked occurrence).
            $transaction = FinancialTransaction::query()
                ->whereKey($input->financialTransactionId)
                ->lockForUpdate()
                ->first();

            if ($transaction === null || (int) $transaction->user_id !== (int) $input->user->id) {
                throw new LedgerIntegrityException(
                    'Financial transaction is not accessible for this user.',
                );
            }

            $link = RecurringPaymentLink::query()
                ->where('occurrence_id', $occurrence->id)
                ->where('financial_transaction_id', $transaction->id)
                ->where('user_id', $input->user->id)
                ->lockForUpdate()
                ->first();

            if ($link === null) {
                throw new LedgerIntegrityException(
                    'No payment link exists for this occurrence and financial transaction.',
                );
            }

            $previousPaid = $this->sumActivePaidAmountMinor($occurrence);
            $linkId = (int) $link->id;
            $transactionAmountMinor = (int) $transaction->amount_minor;

            $link->delete();

            $summary = $this->buildPaymentSummary($input->user, $occurrence);

            $this->auditService->record(
                actor: $input->user,
                action: AuditService::ACTION_RECURRING_PAYMENT_UNLINKED,
                auditableType: RecurringOccurrence::class,
                auditableId: (int) $occurrence->id,
                metadata: [
                    'payment_link_id' => $linkId,
                    'obligation_id' => (int) $obligation->id,
                    'occurrence_id' => (int) $occurrence->id,
                    'financial_transaction_id' => (int) $transaction->id,
                    'currency_code' => $occurrence->currency_code,
                    'transaction_amount_minor' => $transactionAmountMinor,
                    'previous_paid_amount_minor' => $previousPaid,
                    'new_paid_amount_minor' => $summary->paidAmountMinor,
                    'expected_amount_minor' => $summary->expectedAmountMinor,
                    'display_status' => $summary->displayStatus->value,
                ],
            );

            return new UnlinkedRecurringPaymentResult(
                occurrenceId: (int) $occurrence->id,
                financialTransactionId: (int) $transaction->id,
                paymentSummary: $summary,
            );
        });
    }

    public function getOccurrencePaymentSummary(User $user, int $occurrenceId): OccurrencePaymentSummary
    {
        $this->assertValidUser($user);

        $occurrence = RecurringOccurrence::query()
            ->with('obligation')
            ->whereKey($occurrenceId)
            ->first();

        if ($occurrence === null || (int) $occurrence->user_id !== (int) $user->id) {
            throw new LedgerIntegrityException('Recurring occurrence is not accessible for this user.');
        }

        return $this->buildPaymentSummary($user, $occurrence);
    }

    public function getOccurrenceDisplayStatus(User $user, int $occurrenceId): RecurringOccurrenceDisplayStatus
    {
        return $this->getOccurrencePaymentSummary($user, $occurrenceId)->displayStatus;
    }

    /**
     * Builds payment summaries for many owned occurrences without N+1 paid-total queries.
     *
     * @param  iterable<int, RecurringOccurrence>  $occurrences
     * @return array<int, OccurrencePaymentSummary> keyed by occurrence id
     */
    public function getOccurrencePaymentSummaries(User $user, iterable $occurrences): array
    {
        $this->assertValidUser($user);

        $owned = [];
        foreach ($occurrences as $occurrence) {
            if (! $occurrence instanceof RecurringOccurrence) {
                continue;
            }

            if ((int) $occurrence->user_id !== (int) $user->id) {
                throw new LedgerIntegrityException('Recurring occurrence is not accessible for this user.');
            }

            $owned[(int) $occurrence->id] = $occurrence;
        }

        if ($owned === []) {
            return [];
        }

        $occurrenceIds = array_keys($owned);
        $paidByOccurrence = $this->sumActivePaidAmountMinorByOccurrenceIds($occurrenceIds);
        $paymentLinksByOccurrence = $this->paymentLinkSummariesByOccurrenceIds($user, $occurrenceIds);

        $summaries = [];
        foreach ($owned as $occurrenceId => $occurrence) {
            $paymentLinks = $paymentLinksByOccurrence[$occurrenceId] ?? [];
            $allLinkedIds = array_map(
                static fn (RecurringPaymentLinkSummary $link): int => $link->financialTransactionId,
                $paymentLinks,
            );
            $activeLinkedIds = array_values(array_map(
                static fn (RecurringPaymentLinkSummary $link): int => $link->financialTransactionId,
                array_filter(
                    $paymentLinks,
                    static fn (RecurringPaymentLinkSummary $link): bool => $link->isActive,
                ),
            ));

            $summaries[$occurrenceId] = $this->buildPaymentSummaryFromParts(
                occurrence: $occurrence,
                paid: $paidByOccurrence[$occurrenceId] ?? 0,
                allLinkedIds: $allLinkedIds,
                activeLinkedIds: $activeLinkedIds,
                paymentLinks: $paymentLinks,
            );
        }

        return $summaries;
    }

    /**
     * @return array{0: RecurringObligation, 1: RecurringOccurrence}
     */
    private function lockObligationAndOccurrence(User $user, int $occurrenceId): array
    {
        $occurrence = RecurringOccurrence::query()
            ->whereKey($occurrenceId)
            ->first();

        if ($occurrence === null || (int) $occurrence->user_id !== (int) $user->id) {
            throw new LedgerIntegrityException('Recurring occurrence is not accessible for this user.');
        }

        // Lock order: obligation -> occurrence.
        $obligation = RecurringObligation::query()
            ->whereKey($occurrence->obligation_id)
            ->lockForUpdate()
            ->first();

        if ($obligation === null || (int) $obligation->user_id !== (int) $user->id) {
            throw new LedgerIntegrityException('Recurring obligation is not accessible for this user.');
        }

        $lockedOccurrence = RecurringOccurrence::query()
            ->whereKey($occurrence->id)
            ->lockForUpdate()
            ->first();

        if ($lockedOccurrence === null || (int) $lockedOccurrence->user_id !== (int) $user->id) {
            throw new LedgerIntegrityException('Recurring occurrence is not accessible for this user.');
        }

        return [$obligation, $lockedOccurrence];
    }

    private function lockOwnedTransaction(User $user, int $transactionId): FinancialTransaction
    {
        $transaction = FinancialTransaction::query()
            ->with('reversalTransaction')
            ->whereKey($transactionId)
            ->lockForUpdate()
            ->first();

        if ($transaction === null || (int) $transaction->user_id !== (int) $user->id) {
            throw new LedgerIntegrityException('Financial transaction is not accessible for this user.');
        }

        return $transaction;
    }

    private function assertOccurrenceAcceptsPaymentLinks(
        RecurringObligation $obligation,
        RecurringOccurrence $occurrence,
    ): void {
        if ($obligation->status === RecurringObligationStatus::Cancelled) {
            throw new LedgerIntegrityException(
                'Payments cannot be linked to occurrences on a cancelled recurring obligation.',
            );
        }

        if ($occurrence->status === RecurringOccurrenceStatus::Cancelled) {
            throw new LedgerIntegrityException('Payments cannot be linked to a cancelled occurrence.');
        }

        if ($occurrence->status === RecurringOccurrenceStatus::Skipped) {
            throw new LedgerIntegrityException('Payments cannot be linked to a skipped occurrence.');
        }

        if ($occurrence->status !== RecurringOccurrenceStatus::Scheduled) {
            throw new LedgerIntegrityException('Payments can only be linked to scheduled occurrences.');
        }
    }

    private function assertTransactionEligibleForLink(
        FinancialTransaction $transaction,
        RecurringObligation $obligation,
        RecurringOccurrence $occurrence,
    ): void {
        if ($transaction->type !== FinancialTransactionType::Expense) {
            throw new LedgerIntegrityException(
                'Only expense transactions can be linked to recurring bill occurrences.',
            );
        }

        if ($transaction->isReversal()) {
            throw new LedgerIntegrityException(
                'Reversal transactions cannot be linked as recurring payments.',
            );
        }

        if ($transaction->isReversed()) {
            throw new LedgerIntegrityException(
                'Already reversed expense transactions cannot be linked as recurring payments.',
            );
        }

        // Confirm active() semantics (ordinary + not reversed).
        $isActive = FinancialTransaction::query()
            ->active()
            ->whereKey($transaction->id)
            ->exists();

        if (! $isActive) {
            throw new LedgerIntegrityException(
                'Only active expense transactions can be linked as recurring payments.',
            );
        }

        if ($transaction->currency_code !== $occurrence->currency_code
            || $transaction->currency_code !== $obligation->currency_code
        ) {
            throw new LedgerIntegrityException(
                'Payment transaction currency must match the occurrence and obligation currency.',
            );
        }

        if ((int) $transaction->category_id !== (int) $obligation->category_id) {
            throw new LedgerIntegrityException(
                'Payment transaction category must match the recurring obligation expense category.',
            );
        }
    }

    private function buildPaymentSummary(User $user, RecurringOccurrence $occurrence): OccurrencePaymentSummary
    {
        $paymentLinks = $this->paymentLinkSummariesByOccurrenceIds(
            $user,
            [(int) $occurrence->id],
        )[(int) $occurrence->id] ?? [];

        $allLinkedIds = array_map(
            static fn (RecurringPaymentLinkSummary $link): int => $link->financialTransactionId,
            $paymentLinks,
        );
        $activeLinkedIds = array_values(array_map(
            static fn (RecurringPaymentLinkSummary $link): int => $link->financialTransactionId,
            array_filter(
                $paymentLinks,
                static fn (RecurringPaymentLinkSummary $link): bool => $link->isActive,
            ),
        ));

        return $this->buildPaymentSummaryFromParts(
            occurrence: $occurrence,
            paid: $this->sumActivePaidAmountMinor($occurrence),
            allLinkedIds: $allLinkedIds,
            activeLinkedIds: $activeLinkedIds,
            paymentLinks: $paymentLinks,
        );
    }

    /**
     * @param  list<int>  $allLinkedIds
     * @param  list<int>  $activeLinkedIds
     * @param  list<RecurringPaymentLinkSummary>  $paymentLinks
     */
    private function buildPaymentSummaryFromParts(
        RecurringOccurrence $occurrence,
        int $paid,
        array $allLinkedIds,
        array $activeLinkedIds,
        array $paymentLinks = [],
    ): OccurrencePaymentSummary {
        $expected = (int) $occurrence->expected_amount_minor;
        $isPaid = $paid >= $expected;
        $isPartiallyPaid = $paid > 0 && $paid < $expected;
        $remaining = $isPaid ? 0 : Money::checkedSubtract($expected, $paid);
        $overpaid = $paid > $expected ? Money::checkedSubtract($paid, $expected) : 0;

        $dueDate = $this->toDateString($occurrence->due_date);
        $today = CarbonImmutable::now('UTC')->toDateString();
        $isDueToday = $dueDate === $today;
        $isOverdue = $dueDate < $today;

        $displayStatus = $this->deriveDisplayStatus(
            occurrence: $occurrence,
            isPaid: $isPaid,
            isPartiallyPaid: $isPartiallyPaid,
            isOverdue: $isOverdue,
            isDueToday: $isDueToday,
        );

        return new OccurrencePaymentSummary(
            occurrence: $occurrence,
            currencyCode: $occurrence->currency_code,
            expectedAmountMinor: $expected,
            paidAmountMinor: $paid,
            remainingAmountMinor: $remaining,
            overpaidAmountMinor: $overpaid,
            displayStatus: $displayStatus,
            isPaid: $isPaid,
            isPartiallyPaid: $isPartiallyPaid,
            isOverdue: $isOverdue && $occurrence->status === RecurringOccurrenceStatus::Scheduled,
            isDueToday: $isDueToday && $occurrence->status === RecurringOccurrenceStatus::Scheduled,
            activeLinkedTransactionIds: $activeLinkedIds,
            allLinkedTransactionIds: $allLinkedIds,
            paymentLinks: $paymentLinks,
        );
    }

    /**
     * Batch-loads owned payment links with transaction activity flags (no N+1).
     *
     * @param  list<int>  $occurrenceIds
     * @return array<int, list<RecurringPaymentLinkSummary>>
     */
    private function paymentLinkSummariesByOccurrenceIds(User $user, array $occurrenceIds): array
    {
        if ($occurrenceIds === []) {
            return [];
        }

        $links = RecurringPaymentLink::query()
            ->with([
                'financialTransaction:id,user_id,amount_minor,currency_code,reversed_transaction_id',
                'financialTransaction.reversalTransaction:id,reversed_transaction_id',
            ])
            ->where('user_id', $user->id)
            ->whereIn('occurrence_id', $occurrenceIds)
            ->orderBy('occurrence_id')
            ->orderBy('id')
            ->get();

        $activeTxnIds = FinancialTransaction::query()
            ->active()
            ->where('user_id', $user->id)
            ->whereIn(
                'id',
                $links->pluck('financial_transaction_id')->map(static fn ($id): int => (int) $id)->all() ?: [0],
            )
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $activeLookup = array_fill_keys($activeTxnIds, true);

        $grouped = [];
        foreach ($links as $link) {
            $occurrenceId = (int) $link->occurrence_id;
            $transaction = $link->financialTransaction;
            $txnId = (int) $link->financial_transaction_id;
            $isActive = isset($activeLookup[$txnId]);
            $isReversed = $transaction !== null && $transaction->isReversed();

            $grouped[$occurrenceId] ??= [];
            $grouped[$occurrenceId][] = new RecurringPaymentLinkSummary(
                id: (int) $link->id,
                occurrenceId: $occurrenceId,
                financialTransactionId: $txnId,
                amountMinor: $transaction !== null ? (int) $transaction->amount_minor : 0,
                currencyCode: (string) $link->currency_code,
                isActive: $isActive,
                isReversed: $isReversed,
            );
        }

        return $grouped;
    }

    /**
     * @param  list<int>  $occurrenceIds
     * @return array<int, int> occurrence_id => paid_amount_minor
     */
    private function sumActivePaidAmountMinorByOccurrenceIds(array $occurrenceIds): array
    {
        $rows = FinancialTransaction::query()
            ->active()
            ->where('type', FinancialTransactionType::Expense->value)
            ->join('recurring_payment_links', 'recurring_payment_links.financial_transaction_id', '=', 'financial_transactions.id')
            ->whereIn('recurring_payment_links.occurrence_id', $occurrenceIds)
            ->orderBy('financial_transactions.id')
            ->get([
                'recurring_payment_links.occurrence_id as occurrence_id',
                'financial_transactions.amount_minor as amount_minor',
            ]);

        $totals = [];
        foreach ($rows as $row) {
            $occurrenceId = (int) $row->occurrence_id;
            $current = $totals[$occurrenceId] ?? 0;
            $totals[$occurrenceId] = Money::checkedAdd($current, (int) $row->amount_minor);
        }

        return $totals;
    }

    private function deriveDisplayStatus(
        RecurringOccurrence $occurrence,
        bool $isPaid,
        bool $isPartiallyPaid,
        bool $isOverdue,
        bool $isDueToday,
    ): RecurringOccurrenceDisplayStatus {
        if ($occurrence->status === RecurringOccurrenceStatus::Cancelled) {
            return RecurringOccurrenceDisplayStatus::Cancelled;
        }

        if ($occurrence->status === RecurringOccurrenceStatus::Skipped) {
            return RecurringOccurrenceDisplayStatus::Skipped;
        }

        if ($isPaid) {
            return RecurringOccurrenceDisplayStatus::Paid;
        }

        if ($isPartiallyPaid) {
            return RecurringOccurrenceDisplayStatus::PartiallyPaid;
        }

        if ($isOverdue) {
            return RecurringOccurrenceDisplayStatus::Overdue;
        }

        if ($isDueToday) {
            return RecurringOccurrenceDisplayStatus::Due;
        }

        return RecurringOccurrenceDisplayStatus::Upcoming;
    }

    private function sumActivePaidAmountMinor(RecurringOccurrence $occurrence): int
    {
        $amounts = FinancialTransaction::query()
            ->active()
            ->where('type', FinancialTransactionType::Expense->value)
            ->whereIn('id', function ($query) use ($occurrence): void {
                $query->select('financial_transaction_id')
                    ->from('recurring_payment_links')
                    ->where('occurrence_id', $occurrence->id);
            })
            ->orderBy('id')
            ->pluck('amount_minor');

        $total = 0;
        foreach ($amounts as $amount) {
            $total = Money::checkedAdd($total, (int) $amount);
        }

        return $total;
    }

    private function recoverDuplicateLinkAttempt(
        LinkRecurringPaymentInput $input,
        UniqueConstraintViolationException|QueryException $exception,
    ): ?LinkedRecurringPaymentResult {
        $existing = RecurringPaymentLink::query()
            ->where('financial_transaction_id', $input->financialTransactionId)
            ->first();

        if ($existing === null) {
            return null;
        }

        if ((int) $existing->user_id !== (int) $input->user->id) {
            throw new LedgerIntegrityException(
                'This financial transaction is already linked to another recurring occurrence.',
            );
        }

        if ((int) $existing->occurrence_id !== (int) $input->occurrenceId) {
            throw new LedgerIntegrityException(
                'This financial transaction is already linked to another recurring occurrence.',
            );
        }

        $occurrence = RecurringOccurrence::query()->whereKey($existing->occurrence_id)->first();
        if ($occurrence === null) {
            return null;
        }

        return new LinkedRecurringPaymentResult(
            paymentLink: $existing->fresh(['financialTransaction', 'occurrence']) ?? $existing,
            paymentSummary: $this->buildPaymentSummary($input->user, $occurrence),
            wasRecentlyCreated: false,
        );
    }

    private function mapLinkUniqueViolation(
        UniqueConstraintViolationException|QueryException $exception,
        LinkRecurringPaymentInput $input,
    ): LedgerIntegrityException {
        $existing = RecurringPaymentLink::query()
            ->where('financial_transaction_id', $input->financialTransactionId)
            ->first();

        if ($existing !== null && (int) $existing->occurrence_id !== (int) $input->occurrenceId) {
            return new LedgerIntegrityException(
                'This financial transaction is already linked to another recurring occurrence.',
                previous: $exception,
            );
        }

        if ($this->isOccurrenceTransactionUniqueViolation($exception)
            || $this->isTransactionLinkUniqueViolation($exception)
        ) {
            return new LedgerIntegrityException(
                'This financial transaction is already linked to a recurring occurrence.',
                previous: $exception,
            );
        }

        return new LedgerIntegrityException(
            'Recurring payment link could not be created due to a uniqueness conflict.',
            previous: $exception,
        );
    }

    private function isRetryableLinkUniqueViolation(
        UniqueConstraintViolationException|QueryException $exception,
    ): bool {
        return $this->isTransactionLinkUniqueViolation($exception)
            || $this->isOccurrenceTransactionUniqueViolation($exception);
    }

    private function isTransactionLinkUniqueViolation(
        UniqueConstraintViolationException|QueryException $exception,
    ): bool {
        $message = strtolower($exception->getMessage());

        return str_contains($message, strtolower(self::TRANSACTION_LINK_UNIQUE_INDEX))
            || (
                str_contains($message, 'recurring_payment_links')
                && str_contains($message, 'financial_transaction_id')
                && (str_contains($message, 'unique') || (string) $exception->getCode() === '23000')
            );
    }

    private function isOccurrenceTransactionUniqueViolation(
        UniqueConstraintViolationException|QueryException $exception,
    ): bool {
        $message = strtolower($exception->getMessage());

        return str_contains($message, strtolower(self::OCCURRENCE_TRANSACTION_UNIQUE_INDEX));
    }

    private function toDateString(mixed $date): string
    {
        if ($date instanceof CarbonInterface) {
            return $date->toDateString();
        }

        return (string) $date;
    }

    private function assertValidUser(User $user): void
    {
        if ($user->id === null) {
            throw new LedgerIntegrityException(
                'A persisted user is required for recurring payment operations.',
            );
        }
    }
}
