<?php

namespace App\Services;

use App\DataTransferObjects\CreatedRecurringObligationResult;
use App\DataTransferObjects\CreateRecurringObligationInput;
use App\DataTransferObjects\UpdateRecurringObligationInput;
use App\Enums\CategoryType;
use App\Enums\RecurringFrequency;
use App\Enums\RecurringObligationStatus;
use App\Enums\RecurringOccurrenceStatus;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\LedgerIntegrityException;
use App\Models\Category;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\RecurringObligation;
use App\Models\RecurringOccurrence;
use App\Models\User;
use App\Support\MoneyInputParser;
use App\Support\RecurrenceCalculator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecurringObligationService
{
    public const IDEMPOTENCY_UNIQUE_INDEX = 'recurring_obligations_user_id_idempotency_key_unique';

    public const OCCURRENCE_SLOT_UNIQUE_INDEX = 'recurring_occurrences_obligation_id_active_slot_key_unique';

    public const MAX_CONCURRENCY_ATTEMPTS = 3;

    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    /**
     * Creates an active recurring obligation and generates occurrences through the approved horizon.
     *
     * Idempotency: same user + UUID + identical normalized payload returns the original row.
     * Same user + UUID + different payload raises IdempotencyConflictException.
     */
    public function create(CreateRecurringObligationInput $input): CreatedRecurringObligationResult
    {
        $this->assertValidUser($input->user);

        $idempotencyKey = $this->validateUuidIdempotencyKey($input->idempotencyKey);
        $name = $this->validateName($input->name);
        $description = $this->normalizeDescription($input->description);
        $frequency = $this->resolveFrequency($input->frequency);
        $startDate = RecurrenceCalculator::parseDate($input->startDate, 'Obligation start date');
        $anchorDay = $input->anchorDay;
        $endsOfMonth = $input->endsOfMonth;
        RecurrenceCalculator::assertStartDateMatchesRule($startDate, $frequency, $anchorDay, $endsOfMonth);
        $currencyCode = $this->normalizeCurrencyCode($input->currencyCode);

        for ($attempt = 1; $attempt <= self::MAX_CONCURRENCY_ATTEMPTS; $attempt++) {
            $existing = RecurringObligation::query()
                ->with(['occurrences', 'category', 'financialAccount'])
                ->where('user_id', $input->user->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $this->verifyExistingCreateMatch(
                    existing: $existing,
                    user: $input->user,
                    name: $name,
                    description: $description,
                    currencyCode: $currencyCode,
                    amountInput: $input->amount,
                    categoryId: $input->categoryId,
                    financialAccountId: $input->financialAccountId,
                    startDate: $startDate->toDateString(),
                    frequency: $frequency,
                    anchorDay: $anchorDay,
                    endsOfMonth: $endsOfMonth,
                );
            }

            try {
                return DB::transaction(function () use (
                    $input,
                    $idempotencyKey,
                    $name,
                    $description,
                    $frequency,
                    $startDate,
                    $anchorDay,
                    $endsOfMonth,
                    $currencyCode,
                ): CreatedRecurringObligationResult {
                    $lockedExisting = RecurringObligation::query()
                        ->with(['occurrences', 'category', 'financialAccount'])
                        ->where('user_id', $input->user->id)
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($lockedExisting !== null) {
                        return $this->verifyExistingCreateMatch(
                            existing: $lockedExisting,
                            user: $input->user,
                            name: $name,
                            description: $description,
                            currencyCode: $currencyCode,
                            amountInput: $input->amount,
                            categoryId: $input->categoryId,
                            financialAccountId: $input->financialAccountId,
                            startDate: $startDate->toDateString(),
                            frequency: $frequency,
                            anchorDay: $anchorDay,
                            endsOfMonth: $endsOfMonth,
                        );
                    }

                    $currency = $this->resolveActiveCurrency($currencyCode);
                    $amountMinor = MoneyInputParser::parseToMinorUnits(
                        $input->amount,
                        (int) $currency->minor_unit_exponent,
                    );

                    $category = $this->lockOwnedExpenseCategory(
                        user: $input->user,
                        categoryId: $input->categoryId,
                        allowArchived: false,
                    );

                    $account = $this->lockOptionalPreferredAccount(
                        user: $input->user,
                        financialAccountId: $input->financialAccountId,
                        currencyCode: $currency->code,
                        allowArchived: false,
                    );

                    $obligation = RecurringObligation::query()->create([
                        'user_id' => $input->user->id,
                        'idempotency_key' => $idempotencyKey,
                        'name' => $name,
                        'description' => $description,
                        'currency_code' => $currency->code,
                        'amount_minor' => $amountMinor,
                        'category_id' => $category->id,
                        'category_type' => CategoryType::Expense,
                        'financial_account_id' => $account?->id,
                        'start_date' => $startDate->toDateString(),
                        'frequency' => $frequency,
                        'interval_count' => 1,
                        'anchor_day' => $anchorDay,
                        'ends_of_month' => $endsOfMonth,
                        'next_occurrence_date' => null,
                        'status' => RecurringObligationStatus::Active,
                        'paused_at' => null,
                        'cancelled_at' => null,
                    ]);

                    $this->generateOccurrencesForLockedObligation($obligation);

                    $this->auditService->record(
                        actor: $input->user,
                        action: AuditService::ACTION_RECURRING_OBLIGATION_CREATED,
                        auditableType: RecurringObligation::class,
                        auditableId: (int) $obligation->id,
                        metadata: [
                            'name' => $obligation->name,
                            'currency_code' => $obligation->currency_code,
                            'amount_minor' => (int) $obligation->amount_minor,
                            'category_id' => (int) $obligation->category_id,
                            'financial_account_id' => $obligation->financial_account_id !== null
                                ? (int) $obligation->financial_account_id
                                : null,
                            'start_date' => $obligation->start_date->toDateString(),
                            'frequency' => $frequency->value,
                            'anchor_day' => (int) $obligation->anchor_day,
                            'ends_of_month' => (bool) $obligation->ends_of_month,
                            'idempotency_key' => $idempotencyKey,
                        ],
                    );

                    return new CreatedRecurringObligationResult(
                        obligation: $obligation->fresh(['occurrences', 'category', 'financialAccount']) ?? $obligation,
                        wasRecentlyCreated: true,
                    );
                });
            } catch (UniqueConstraintViolationException $exception) {
                if ($this->isIdempotencyUniqueViolation($exception)) {
                    $recovered = RecurringObligation::query()
                        ->with(['occurrences', 'category', 'financialAccount'])
                        ->where('user_id', $input->user->id)
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();

                    if ($recovered !== null) {
                        return $this->verifyExistingCreateMatch(
                            existing: $recovered,
                            user: $input->user,
                            name: $name,
                            description: $description,
                            currencyCode: $currencyCode,
                            amountInput: $input->amount,
                            categoryId: $input->categoryId,
                            financialAccountId: $input->financialAccountId,
                            startDate: $startDate->toDateString(),
                            frequency: $frequency,
                            anchorDay: $anchorDay,
                            endsOfMonth: $endsOfMonth,
                        );
                    }

                    if ($attempt < self::MAX_CONCURRENCY_ATTEMPTS) {
                        continue;
                    }
                }

                throw $exception;
            } catch (QueryException $exception) {
                if (
                    $this->isIdempotencyUniqueViolation($exception)
                    && $attempt < self::MAX_CONCURRENCY_ATTEMPTS
                ) {
                    continue;
                }

                if ($this->isIdempotencyUniqueViolation($exception)) {
                    $recovered = RecurringObligation::query()
                        ->with(['occurrences', 'category', 'financialAccount'])
                        ->where('user_id', $input->user->id)
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();

                    if ($recovered !== null) {
                        return $this->verifyExistingCreateMatch(
                            existing: $recovered,
                            user: $input->user,
                            name: $name,
                            description: $description,
                            currencyCode: $currencyCode,
                            amountInput: $input->amount,
                            categoryId: $input->categoryId,
                            financialAccountId: $input->financialAccountId,
                            startDate: $startDate->toDateString(),
                            frequency: $frequency,
                            anchorDay: $anchorDay,
                            endsOfMonth: $endsOfMonth,
                        );
                    }
                }

                throw $exception;
            }
        }

        throw new LedgerIntegrityException(
            'Unable to create recurring obligation due to concurrent contention.',
        );
    }

    /**
     * Updates mutable obligation fields. Amount-only changes update future unlinked scheduled
     * occurrences in place. Schedule/frequency/start/anchor changes cancel eligible future
     * unlinked scheduled rows and regenerate; occupied preserved slots are never overwritten.
     */
    public function update(UpdateRecurringObligationInput $input): RecurringObligation
    {
        $this->assertValidUser($input->user);

        $name = $this->validateName($input->name);
        $description = $this->normalizeDescription($input->description);
        $frequency = $this->resolveFrequency($input->frequency);
        $startDate = RecurrenceCalculator::parseDate($input->startDate, 'Obligation start date');
        $anchorDay = $input->anchorDay;
        $endsOfMonth = $input->endsOfMonth;
        RecurrenceCalculator::assertStartDateMatchesRule($startDate, $frequency, $anchorDay, $endsOfMonth);

        return DB::transaction(function () use (
            $input,
            $name,
            $description,
            $frequency,
            $startDate,
            $anchorDay,
            $endsOfMonth,
        ): RecurringObligation {
            $obligation = $this->lockOwnedObligation($input->user, $input->obligationId);

            if ($obligation->status === RecurringObligationStatus::Cancelled) {
                throw new LedgerIntegrityException('Cancelled recurring obligations cannot be edited.');
            }

            $currency = $this->lockCurrency($obligation->currency_code);
            $amountMinor = MoneyInputParser::parseToMinorUnits(
                $input->amount,
                (int) $currency->minor_unit_exponent,
            );

            $category = $this->lockOwnedExpenseCategory(
                user: $input->user,
                categoryId: $input->categoryId,
                allowArchived: (int) $obligation->category_id === (int) $input->categoryId,
            );

            $account = $this->lockOptionalPreferredAccount(
                user: $input->user,
                financialAccountId: $input->financialAccountId,
                currencyCode: $obligation->currency_code,
                allowArchived: $obligation->financial_account_id !== null
                    && (int) $obligation->financial_account_id === (int) ($input->financialAccountId ?? 0),
            );

            $before = $this->obligationSnapshot($obligation);

            $scheduleChanged = $startDate->toDateString() !== $obligation->start_date->toDateString()
                || $frequency !== $obligation->frequency
                || $anchorDay !== (int) $obligation->anchor_day
                || $endsOfMonth !== (bool) $obligation->ends_of_month;

            $amountChanged = $amountMinor !== (int) $obligation->amount_minor;

            $obligation->name = $name;
            $obligation->description = $description;
            $obligation->amount_minor = $amountMinor;
            $obligation->category_id = $category->id;
            $obligation->category_type = CategoryType::Expense;
            $obligation->financial_account_id = $account?->id;
            $obligation->start_date = $startDate->toDateString();
            $obligation->frequency = $frequency;
            $obligation->interval_count = 1;
            $obligation->anchor_day = $anchorDay;
            $obligation->ends_of_month = $endsOfMonth;
            $obligation->save();

            if ($scheduleChanged) {
                $this->cancelEligibleFutureUnlinkedScheduled($obligation);
                if ($obligation->status === RecurringObligationStatus::Active) {
                    $this->generateOccurrencesForLockedObligation($obligation);
                } else {
                    $this->refreshNextOccurrenceDate($obligation);
                }
            } elseif ($amountChanged) {
                $this->updateFutureUnlinkedScheduledAmounts($obligation, $amountMinor);
                $this->refreshNextOccurrenceDate($obligation);
            } else {
                $this->refreshNextOccurrenceDate($obligation);
            }

            $fresh = $obligation->fresh(['occurrences', 'category', 'financialAccount']) ?? $obligation;

            $this->auditService->record(
                actor: $input->user,
                action: AuditService::ACTION_RECURRING_OBLIGATION_UPDATED,
                auditableType: RecurringObligation::class,
                auditableId: (int) $fresh->id,
                metadata: [
                    'before' => $before,
                    'after' => $this->obligationSnapshot($fresh),
                    'schedule_changed' => $scheduleChanged,
                    'amount_changed' => $amountChanged,
                ],
            );

            return $fresh;
        });
    }

    public function pause(User $user, int $obligationId): RecurringObligation
    {
        $this->assertValidUser($user);

        return DB::transaction(function () use ($user, $obligationId): RecurringObligation {
            $obligation = $this->lockOwnedObligation($user, $obligationId);

            if ($obligation->status === RecurringObligationStatus::Cancelled) {
                throw new LedgerIntegrityException('Cancelled recurring obligations cannot be paused.');
            }

            if ($obligation->status === RecurringObligationStatus::Paused) {
                return $obligation->fresh(['occurrences', 'category', 'financialAccount']) ?? $obligation;
            }

            $before = $obligation->status->value;
            $obligation->status = RecurringObligationStatus::Paused;
            $obligation->paused_at = now('UTC');
            $obligation->cancelled_at = null;
            $obligation->save();

            $this->auditService->record(
                actor: $user,
                action: AuditService::ACTION_RECURRING_OBLIGATION_PAUSED,
                auditableType: RecurringObligation::class,
                auditableId: (int) $obligation->id,
                metadata: [
                    'previous_status' => $before,
                    'status' => RecurringObligationStatus::Paused->value,
                ],
            );

            return $obligation->fresh(['occurrences', 'category', 'financialAccount']) ?? $obligation;
        });
    }

    public function resume(User $user, int $obligationId): RecurringObligation
    {
        $this->assertValidUser($user);

        return DB::transaction(function () use ($user, $obligationId): RecurringObligation {
            $obligation = $this->lockOwnedObligation($user, $obligationId);

            if ($obligation->status === RecurringObligationStatus::Cancelled) {
                throw new LedgerIntegrityException('Cancelled recurring obligations cannot be resumed.');
            }

            if ($obligation->status === RecurringObligationStatus::Active) {
                return $obligation->fresh(['occurrences', 'category', 'financialAccount']) ?? $obligation;
            }

            $before = $obligation->status->value;
            $obligation->status = RecurringObligationStatus::Active;
            $obligation->paused_at = null;
            $obligation->cancelled_at = null;
            $obligation->save();

            $this->generateOccurrencesForLockedObligation($obligation);

            $this->auditService->record(
                actor: $user,
                action: AuditService::ACTION_RECURRING_OBLIGATION_RESUMED,
                auditableType: RecurringObligation::class,
                auditableId: (int) $obligation->id,
                metadata: [
                    'previous_status' => $before,
                    'status' => RecurringObligationStatus::Active->value,
                ],
            );

            return $obligation->fresh(['occurrences', 'category', 'financialAccount']) ?? $obligation;
        });
    }

    public function cancel(User $user, int $obligationId): RecurringObligation
    {
        $this->assertValidUser($user);

        return DB::transaction(function () use ($user, $obligationId): RecurringObligation {
            $obligation = $this->lockOwnedObligation($user, $obligationId);

            if ($obligation->status === RecurringObligationStatus::Cancelled) {
                return $obligation->fresh(['occurrences', 'category', 'financialAccount']) ?? $obligation;
            }

            $before = $obligation->status->value;
            $obligation->status = RecurringObligationStatus::Cancelled;
            $obligation->cancelled_at = now('UTC');
            // Preserve paused_at if present; CHECK allows cancelled with cancelled_at set.
            $obligation->paused_at = null;
            $obligation->next_occurrence_date = null;
            $obligation->save();

            $this->cancelEligibleFutureUnlinkedScheduled($obligation);

            $this->auditService->record(
                actor: $user,
                action: AuditService::ACTION_RECURRING_OBLIGATION_CANCELLED,
                auditableType: RecurringObligation::class,
                auditableId: (int) $obligation->id,
                metadata: [
                    'previous_status' => $before,
                    'status' => RecurringObligationStatus::Cancelled->value,
                ],
            );

            return $obligation->fresh(['occurrences', 'category', 'financialAccount']) ?? $obligation;
        });
    }

    /**
     * Idempotently generates missing occurrences through the three-month UTC horizon.
     * Paused and cancelled obligations do not generate new rows.
     */
    public function generateOccurrences(User $user, int $obligationId): RecurringObligation
    {
        $this->assertValidUser($user);

        return DB::transaction(function () use ($user, $obligationId): RecurringObligation {
            $obligation = $this->lockOwnedObligation($user, $obligationId);

            if ($obligation->status !== RecurringObligationStatus::Active) {
                throw new LedgerIntegrityException(
                    'Occurrences can only be generated for active recurring obligations.',
                );
            }

            $this->generateOccurrencesForLockedObligation($obligation);

            return $obligation->fresh(['occurrences', 'category', 'financialAccount']) ?? $obligation;
        });
    }

    public function skipOccurrence(User $user, int $occurrenceId): RecurringOccurrence
    {
        $this->assertValidUser($user);

        return DB::transaction(function () use ($user, $occurrenceId): RecurringOccurrence {
            $occurrence = RecurringOccurrence::query()
                ->whereKey($occurrenceId)
                ->lockForUpdate()
                ->first();

            if ($occurrence === null || (int) $occurrence->user_id !== (int) $user->id) {
                throw new LedgerIntegrityException('Recurring occurrence is not accessible for this user.');
            }

            $obligation = RecurringObligation::query()
                ->whereKey($occurrence->obligation_id)
                ->lockForUpdate()
                ->first();

            if ($obligation === null || (int) $obligation->user_id !== (int) $user->id) {
                throw new LedgerIntegrityException('Recurring obligation is not accessible for this user.');
            }

            if ($occurrence->status === RecurringOccurrenceStatus::Skipped) {
                return $occurrence->fresh(['paymentLinks', 'obligation']) ?? $occurrence;
            }

            if ($occurrence->status !== RecurringOccurrenceStatus::Scheduled) {
                throw new LedgerIntegrityException('Only scheduled recurring occurrences can be skipped.');
            }

            if ($this->occurrenceHasEffectivePayment($occurrence)) {
                throw new LedgerIntegrityException(
                    'Occurrences with an effective linked payment cannot be skipped.',
                );
            }

            $occurrence->status = RecurringOccurrenceStatus::Skipped;
            $occurrence->skipped_at = now('UTC');
            $occurrence->cancelled_at = null;
            // Model boot keeps active_slot_key = due_date for skipped.
            $occurrence->save();

            $this->refreshNextOccurrenceDate($obligation);

            $this->auditService->record(
                actor: $user,
                action: AuditService::ACTION_RECURRING_OCCURRENCE_SKIPPED,
                auditableType: RecurringOccurrence::class,
                auditableId: (int) $occurrence->id,
                metadata: [
                    'obligation_id' => (int) $obligation->id,
                    'due_date' => $occurrence->due_date->toDateString(),
                    'active_slot_key' => $occurrence->active_slot_key instanceof CarbonInterface
                        ? $occurrence->active_slot_key->toDateString()
                        : (string) $occurrence->active_slot_key,
                ],
            );

            return $occurrence->fresh(['paymentLinks', 'obligation']) ?? $occurrence;
        });
    }

    /**
     * Must be called with the obligation already locked for update.
     */
    private function generateOccurrencesForLockedObligation(RecurringObligation $obligation): void
    {
        if ($obligation->status !== RecurringObligationStatus::Active) {
            $this->refreshNextOccurrenceDate($obligation);

            return;
        }

        $frequency = $obligation->frequency instanceof RecurringFrequency
            ? $obligation->frequency
            : RecurringFrequency::from((string) $obligation->frequency);

        $dueDates = RecurrenceCalculator::datesThroughHorizon(
            startDate: $obligation->start_date->toDateString(),
            frequency: $frequency,
            anchorDay: (int) $obligation->anchor_day,
            endsOfMonth: (bool) $obligation->ends_of_month,
        );

        foreach ($dueDates as $dueDate) {
            $this->insertScheduledOccurrenceIfSlotFree($obligation, $dueDate);
        }

        $this->refreshNextOccurrenceDate($obligation);
    }

    private function insertScheduledOccurrenceIfSlotFree(
        RecurringObligation $obligation,
        string $dueDate,
    ): void {
        $occupied = RecurringOccurrence::query()
            ->where('obligation_id', $obligation->id)
            ->where('active_slot_key', $dueDate)
            ->lockForUpdate()
            ->exists();

        if ($occupied) {
            // Scheduled (including paid) or skipped already owns the slot. Idempotent no-op.
            return;
        }

        try {
            RecurringOccurrence::query()->create([
                'user_id' => $obligation->user_id,
                'obligation_id' => $obligation->id,
                'due_date' => $dueDate,
                'expected_amount_minor' => (int) $obligation->amount_minor,
                'currency_code' => $obligation->currency_code,
                'status' => RecurringOccurrenceStatus::Scheduled,
                'active_slot_key' => $dueDate,
                'skipped_at' => null,
                'cancelled_at' => null,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            if ($this->isOccurrenceSlotUniqueViolation($exception)) {
                return;
            }

            throw $exception;
        } catch (QueryException $exception) {
            if ($this->isOccurrenceSlotUniqueViolation($exception)) {
                return;
            }

            throw $exception;
        }
    }

    private function cancelEligibleFutureUnlinkedScheduled(RecurringObligation $obligation): void
    {
        $today = CarbonImmutable::now('UTC')->toDateString();

        $candidates = RecurringOccurrence::query()
            ->where('obligation_id', $obligation->id)
            ->where('status', RecurringOccurrenceStatus::Scheduled->value)
            ->whereDate('due_date', '>=', $today)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($candidates as $occurrence) {
            if ($this->occurrenceHasAnyPaymentLink($occurrence)) {
                // Preserve payment-linked history; slot stays occupied.
                continue;
            }

            $occurrence->status = RecurringOccurrenceStatus::Cancelled;
            $occurrence->cancelled_at = now('UTC');
            $occurrence->skipped_at = null;
            $occurrence->active_slot_key = null;
            $occurrence->save();
        }
    }

    private function updateFutureUnlinkedScheduledAmounts(
        RecurringObligation $obligation,
        int $amountMinor,
    ): void {
        $today = CarbonImmutable::now('UTC')->toDateString();

        $candidates = RecurringOccurrence::query()
            ->where('obligation_id', $obligation->id)
            ->where('status', RecurringOccurrenceStatus::Scheduled->value)
            ->whereDate('due_date', '>=', $today)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($candidates as $occurrence) {
            if ($this->occurrenceHasAnyPaymentLink($occurrence)) {
                continue;
            }

            $occurrence->expected_amount_minor = $amountMinor;
            $occurrence->save();
        }
    }

    private function refreshNextOccurrenceDate(RecurringObligation $obligation): void
    {
        if ($obligation->status === RecurringObligationStatus::Cancelled) {
            $obligation->next_occurrence_date = null;
            $obligation->save();

            return;
        }

        $today = CarbonImmutable::now('UTC')->toDateString();

        $nextScheduled = RecurringOccurrence::query()
            ->where('obligation_id', $obligation->id)
            ->where('status', RecurringOccurrenceStatus::Scheduled->value)
            ->whereDate('due_date', '>=', $today)
            ->orderBy('due_date')
            ->value('due_date');

        if ($nextScheduled !== null) {
            $obligation->next_occurrence_date = $nextScheduled instanceof CarbonInterface
                ? $nextScheduled->toDateString()
                : (string) $nextScheduled;
            $obligation->save();

            return;
        }

        // Cursor beyond the current horizon for the next generation pass.
        $frequency = $obligation->frequency instanceof RecurringFrequency
            ? $obligation->frequency
            : RecurringFrequency::from((string) $obligation->frequency);

        $latest = RecurringOccurrence::query()
            ->where('obligation_id', $obligation->id)
            ->orderByDesc('due_date')
            ->value('due_date');

        if ($latest === null) {
            $obligation->next_occurrence_date = $obligation->start_date->toDateString();
            $obligation->save();

            return;
        }

        $latestDate = $latest instanceof CarbonInterface
            ? CarbonImmutable::parse($latest->toDateString(), 'UTC')
            : RecurrenceCalculator::parseDate((string) $latest, 'Occurrence due date');

        $seriesStart = RecurrenceCalculator::parseDate(
            $obligation->start_date->toDateString(),
            'Obligation start date',
        );

        $obligation->next_occurrence_date = RecurrenceCalculator::nextAfter(
            $latestDate,
            $seriesStart,
            $frequency,
            (int) $obligation->anchor_day,
            (bool) $obligation->ends_of_month,
        )->toDateString();
        $obligation->save();
    }

    private function occurrenceHasAnyPaymentLink(RecurringOccurrence $occurrence): bool
    {
        return $occurrence->paymentLinks()->exists();
    }

    /**
     * Effective payment uses FinancialTransaction::scopeActive semantics
     * (ordinary, not a reversal, and not itself reversed).
     */
    private function occurrenceHasEffectivePayment(RecurringOccurrence $occurrence): bool
    {
        return FinancialTransaction::query()
            ->active()
            ->whereIn('id', function ($query) use ($occurrence): void {
                $query->select('financial_transaction_id')
                    ->from('recurring_payment_links')
                    ->where('occurrence_id', $occurrence->id);
            })
            ->exists();
    }

    private function verifyExistingCreateMatch(
        RecurringObligation $existing,
        User $user,
        string $name,
        ?string $description,
        string $currencyCode,
        string $amountInput,
        int $categoryId,
        ?int $financialAccountId,
        string $startDate,
        RecurringFrequency $frequency,
        int $anchorDay,
        bool $endsOfMonth,
    ): CreatedRecurringObligationResult {
        $currency = Currency::query()->where('code', $existing->currency_code)->first();
        if ($currency === null) {
            throw new LedgerIntegrityException('Existing recurring obligation currency could not be resolved.');
        }

        $amountMinor = MoneyInputParser::parseToMinorUnits(
            $amountInput,
            (int) $currency->minor_unit_exponent,
        );

        $matches = (int) $existing->user_id === (int) $user->id
            && $existing->name === $name
            && $existing->description === $description
            && $existing->currency_code === $currencyCode
            && (int) $existing->amount_minor === $amountMinor
            && (int) $existing->category_id === $categoryId
            && (int) ($existing->financial_account_id ?? 0) === (int) ($financialAccountId ?? 0)
            && $existing->start_date->toDateString() === $startDate
            && $existing->frequency === $frequency
            && (int) $existing->anchor_day === $anchorDay
            && (bool) $existing->ends_of_month === $endsOfMonth;

        if (! $matches) {
            throw new IdempotencyConflictException(
                'Recurring obligation idempotency key was reused with a different payload.',
            );
        }

        return new CreatedRecurringObligationResult(
            obligation: $existing->relationLoaded('occurrences')
                ? $existing
                : ($existing->fresh(['occurrences', 'category', 'financialAccount']) ?? $existing),
            wasRecentlyCreated: false,
        );
    }

    private function lockOwnedObligation(User $user, int $obligationId): RecurringObligation
    {
        $obligation = RecurringObligation::query()
            ->whereKey($obligationId)
            ->lockForUpdate()
            ->first();

        if ($obligation === null || (int) $obligation->user_id !== (int) $user->id) {
            throw new LedgerIntegrityException('Recurring obligation is not accessible for this user.');
        }

        return $obligation;
    }

    private function lockOwnedExpenseCategory(
        User $user,
        int $categoryId,
        bool $allowArchived,
    ): Category {
        $category = Category::query()
            ->whereKey($categoryId)
            ->lockForUpdate()
            ->first();

        if ($category === null || (int) $category->user_id !== (int) $user->id) {
            throw new LedgerIntegrityException('Category is not accessible for this user.');
        }

        if ($category->type !== CategoryType::Expense) {
            throw new LedgerIntegrityException('Recurring obligations require an expense category.');
        }

        if ($category->is_archived && ! $allowArchived) {
            throw new LedgerIntegrityException('Archived categories cannot be used for new recurring obligations.');
        }

        return $category;
    }

    private function lockOptionalPreferredAccount(
        User $user,
        ?int $financialAccountId,
        string $currencyCode,
        bool $allowArchived,
    ): ?FinancialAccount {
        if ($financialAccountId === null) {
            return null;
        }

        $account = FinancialAccount::query()
            ->whereKey($financialAccountId)
            ->lockForUpdate()
            ->first();

        if ($account === null || (int) $account->user_id !== (int) $user->id) {
            throw new LedgerIntegrityException('Preferred financial account is not accessible for this user.');
        }

        if ($account->currency_code !== $currencyCode) {
            throw new LedgerIntegrityException(
                'Preferred financial account currency must match the obligation currency.',
            );
        }

        if ($account->is_archived && ! $allowArchived) {
            throw new LedgerIntegrityException(
                'Archived financial accounts cannot be set as the preferred account for a recurring obligation.',
            );
        }

        return $account;
    }

    private function resolveActiveCurrency(string $currencyCode): Currency
    {
        $currency = Currency::query()
            ->where('code', $currencyCode)
            ->lockForUpdate()
            ->first();

        if ($currency === null || ! $currency->is_active) {
            throw new LedgerIntegrityException(sprintf(
                'Currency [%s] is not an active supported currency.',
                $currencyCode,
            ));
        }

        return $currency;
    }

    private function lockCurrency(string $currencyCode): Currency
    {
        $currency = Currency::query()
            ->where('code', $currencyCode)
            ->lockForUpdate()
            ->first();

        if ($currency === null) {
            throw new LedgerIntegrityException(sprintf(
                'Currency [%s] could not be resolved for this recurring obligation.',
                $currencyCode,
            ));
        }

        return $currency;
    }

    private function resolveFrequency(RecurringFrequency|string $frequency): RecurringFrequency
    {
        if ($frequency instanceof RecurringFrequency) {
            return $frequency;
        }

        $resolved = RecurringFrequency::tryFrom(strtolower(trim($frequency)));

        if ($resolved === null) {
            throw new LedgerIntegrityException('Unsupported recurring frequency.');
        }

        return $resolved;
    }

    private function validateName(string $name): string
    {
        $trimmed = trim($name);

        if ($trimmed === '' || strlen($trimmed) > 120) {
            throw new LedgerIntegrityException(
                'Recurring obligation name is required and must be at most 120 characters.',
            );
        }

        return $trimmed;
    }

    private function normalizeDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }

        $trimmed = trim($description);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeCurrencyCode(string $currencyCode): string
    {
        $normalized = strtoupper(trim($currencyCode));

        if (strlen($normalized) !== 3 || ! ctype_alpha($normalized)) {
            throw new LedgerIntegrityException('Currency code must be a 3-letter ISO 4217 code.');
        }

        return $normalized;
    }

    private function validateUuidIdempotencyKey(string $idempotencyKey): string
    {
        $normalized = strtolower(trim($idempotencyKey));

        if (! Str::isUuid($normalized)) {
            throw new LedgerIntegrityException('Recurring obligation idempotency key must be a valid UUID.');
        }

        return $normalized;
    }

    private function assertValidUser(User $user): void
    {
        if ($user->id === null) {
            throw new LedgerIntegrityException('A persisted user is required for recurring obligation operations.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function obligationSnapshot(RecurringObligation $obligation): array
    {
        return [
            'name' => $obligation->name,
            'description' => $obligation->description,
            'amount_minor' => (int) $obligation->amount_minor,
            'category_id' => (int) $obligation->category_id,
            'financial_account_id' => $obligation->financial_account_id !== null
                ? (int) $obligation->financial_account_id
                : null,
            'start_date' => $obligation->start_date->toDateString(),
            'frequency' => $obligation->frequency instanceof RecurringFrequency
                ? $obligation->frequency->value
                : (string) $obligation->frequency,
            'anchor_day' => (int) $obligation->anchor_day,
            'ends_of_month' => (bool) $obligation->ends_of_month,
            'status' => $obligation->status instanceof RecurringObligationStatus
                ? $obligation->status->value
                : (string) $obligation->status,
            'currency_code' => $obligation->currency_code,
        ];
    }

    private function isIdempotencyUniqueViolation(UniqueConstraintViolationException|QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, strtolower(self::IDEMPOTENCY_UNIQUE_INDEX))
            || str_contains($message, 'idempotency_key')
            || (
                (str_contains($message, 'unique') || (string) $exception->getCode() === '23000')
                && str_contains($message, 'recurring_obligations')
            );
    }

    private function isOccurrenceSlotUniqueViolation(UniqueConstraintViolationException|QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, strtolower(self::OCCURRENCE_SLOT_UNIQUE_INDEX))
            || str_contains($message, 'active_slot_key');
    }
}
