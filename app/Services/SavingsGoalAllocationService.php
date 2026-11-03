<?php

namespace App\Services;

use App\DataTransferObjects\AllocateSavingsGoalInput;
use App\DataTransferObjects\CreatedSavingsGoalEventResult;
use App\DataTransferObjects\SavingsGoalEventListResult;
use App\Enums\SavingsGoalEventType;
use App\Enums\SavingsGoalStatus;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\LedgerIntegrityException;
use App\Models\SavingsGoal;
use App\Models\SavingsGoalEvent;
use App\Models\User;
use App\Support\IdempotencyUniqueConstraint;
use App\Support\Money;
use App\Support\SavingsGoalEventAggregation;
use App\Support\TransientTransactionFailure;
use App\Support\TransientTransactionRetryBackoff;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SavingsGoalAllocationService
{
    public const IDEMPOTENCY_UNIQUE_INDEX = 'savings_goal_events_user_id_idempotency_key_unique';

    public const MAX_CONCURRENCY_ATTEMPTS = 3;

    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    public function contribute(AllocateSavingsGoalInput $input): CreatedSavingsGoalEventResult
    {
        return $this->allocate($input, SavingsGoalEventType::Contribution);
    }

    public function withdraw(AllocateSavingsGoalInput $input): CreatedSavingsGoalEventResult
    {
        return $this->allocate($input, SavingsGoalEventType::Withdrawal);
    }

    public function getNetAllocatedMinor(User $user, int $goalId): int
    {
        $this->assertValidUser($user);
        $goal = $this->findOwnedGoal($user, $goalId);

        return $this->sumNetAllocatedMinorForGoal((int) $goal->id);
    }

    public function getEvents(
        User $user,
        int $goalId,
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE,
    ): SavingsGoalEventListResult {
        $this->assertValidUser($user);
        $this->findOwnedGoal($user, $goalId);

        $page = max(1, $page);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));

        $paginator = SavingsGoalEvent::query()
            ->where('user_id', $user->id)
            ->where('savings_goal_id', $goalId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(perPage: $perPage, page: $page);

        return new SavingsGoalEventListResult(
            events: collect($paginator->items()),
            total: $paginator->total(),
            perPage: $paginator->perPage(),
            currentPage: $paginator->currentPage(),
            lastPage: $paginator->lastPage(),
        );
    }

    private function allocate(
        AllocateSavingsGoalInput $input,
        SavingsGoalEventType $type,
    ): CreatedSavingsGoalEventResult {
        $this->assertValidUser($input->user);

        $idempotencyKey = $this->validateUuidIdempotencyKey($input->idempotencyKey);
        $amountMinor = $this->validateAmountMinor($input->amountMinor);
        $note = $this->normalizeNote($input->note);

        $lastTransientFailure = null;

        for ($attempt = 1; $attempt <= self::MAX_CONCURRENCY_ATTEMPTS; $attempt++) {
            // Re-evaluate UUID each attempt so a concurrent winner committed during
            // deadlock rollback/backoff is observed as an unlocked replay.
            $existing = $this->findEventByIdempotencyKey($input->user, $idempotencyKey);

            if ($existing !== null) {
                return $this->verifyExistingEventMatch(
                    existing: $existing,
                    goalId: $input->goalId,
                    type: $type,
                    amountMinor: $amountMinor,
                    note: $note,
                );
            }

            try {
                return $this->executeAllocationAttempt(
                    input: $input,
                    idempotencyKey: $idempotencyKey,
                    amountMinor: $amountMinor,
                    note: $note,
                    type: $type,
                );
            } catch (UniqueConstraintViolationException $exception) {
                if (! $this->isIdempotencyUniqueViolation($exception)) {
                    throw $exception;
                }

                $recovered = $this->recoverIdempotencyRace(
                    user: $input->user,
                    idempotencyKey: $idempotencyKey,
                    goalId: $input->goalId,
                    type: $type,
                    amountMinor: $amountMinor,
                    note: $note,
                );

                if ($recovered !== null) {
                    return $recovered;
                }
            } catch (QueryException $exception) {
                if ($this->isIdempotencyUniqueViolation($exception)) {
                    $recovered = $this->recoverIdempotencyRace(
                        user: $input->user,
                        idempotencyKey: $idempotencyKey,
                        goalId: $input->goalId,
                        type: $type,
                        amountMinor: $amountMinor,
                        note: $note,
                    );

                    if ($recovered !== null) {
                        return $recovered;
                    }

                    continue;
                }

                if (
                    TransientTransactionFailure::isRetryable($exception)
                    && $attempt < self::MAX_CONCURRENCY_ATTEMPTS
                ) {
                    $lastTransientFailure = $exception;
                    TransientTransactionRetryBackoff::pauseBeforeRetry(
                        $exception,
                        $attempt,
                        self::MAX_CONCURRENCY_ATTEMPTS,
                    );

                    continue;
                }

                throw $exception;
            }
        }

        throw new LedgerIntegrityException(
            message: 'Unable to record savings allocation due to concurrent contention.',
            previous: $lastTransientFailure,
        );
    }

    /**
     * Single allocation attempt inside a fresh DB transaction.
     *
     * Locking order: owned goal row first, then plain UUID recheck (no missing-key
     * FOR UPDATE probe). Unique(user_id, idempotency_key) remains the cross-goal backstop.
     */
    protected function executeAllocationAttempt(
        AllocateSavingsGoalInput $input,
        string $idempotencyKey,
        int $amountMinor,
        ?string $note,
        SavingsGoalEventType $type,
    ): CreatedSavingsGoalEventResult {
        return DB::transaction(function () use (
            $input,
            $idempotencyKey,
            $amountMinor,
            $note,
            $type,
        ): CreatedSavingsGoalEventResult {
            $goal = $this->lockOwnedGoal($input->user, $input->goalId);

            $existing = $this->findEventByIdempotencyKey($input->user, $idempotencyKey);

            if ($existing !== null) {
                return $this->verifyExistingEventMatch(
                    existing: $existing,
                    goalId: $input->goalId,
                    type: $type,
                    amountMinor: $amountMinor,
                    note: $note,
                );
            }

            if ($goal->status === SavingsGoalStatus::Archived) {
                throw new LedgerIntegrityException(
                    'Archived savings goals cannot accept new allocations.',
                );
            }

            $netAllocated = $this->sumNetAllocatedMinorForGoal((int) $goal->id);

            if ($type === SavingsGoalEventType::Contribution) {
                // Reject aggregates that would overflow Money bounds.
                Money::checkedAdd($netAllocated, $amountMinor);
            } else {
                if ($amountMinor > $netAllocated) {
                    throw new LedgerIntegrityException(
                        'Withdrawal exceeds the current net allocated amount.',
                    );
                }

                Money::checkedSubtract($netAllocated, $amountMinor);
            }

            $event = SavingsGoalEvent::query()->create([
                'user_id' => $input->user->id,
                'savings_goal_id' => $goal->id,
                'idempotency_key' => $idempotencyKey,
                'type' => $type,
                'amount_minor' => $amountMinor,
                'currency_code' => $goal->currency_code,
                'note' => $note,
            ]);

            $this->auditService->record(
                actor: $input->user,
                action: $type === SavingsGoalEventType::Contribution
                    ? AuditService::ACTION_SAVINGS_GOAL_CONTRIBUTED
                    : AuditService::ACTION_SAVINGS_GOAL_WITHDRAWN,
                auditableType: SavingsGoal::class,
                auditableId: (int) $goal->id,
                metadata: [
                    'savings_goal_event_id' => (int) $event->id,
                    'type' => $type->value,
                    'amount_minor' => $amountMinor,
                    'currency_code' => $goal->currency_code,
                    'note' => $note,
                    'idempotency_key' => $idempotencyKey,
                ],
            );

            return new CreatedSavingsGoalEventResult(
                event: $event->fresh(['currency', 'savingsGoal']) ?? $event,
                wasRecentlyCreated: true,
            );
        });
    }

    private function recoverIdempotencyRace(
        User $user,
        string $idempotencyKey,
        int $goalId,
        SavingsGoalEventType $type,
        int $amountMinor,
        ?string $note,
    ): ?CreatedSavingsGoalEventResult {
        $raced = $this->findEventByIdempotencyKey($user, $idempotencyKey);

        if ($raced === null) {
            return null;
        }

        return $this->verifyExistingEventMatch(
            existing: $raced,
            goalId: $goalId,
            type: $type,
            amountMinor: $amountMinor,
            note: $note,
        );
    }

    private function findEventByIdempotencyKey(User $user, string $idempotencyKey): ?SavingsGoalEvent
    {
        return SavingsGoalEvent::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    private function verifyExistingEventMatch(
        SavingsGoalEvent $existing,
        int $goalId,
        SavingsGoalEventType $type,
        int $amountMinor,
        ?string $note,
    ): CreatedSavingsGoalEventResult {
        $existingType = $existing->type instanceof SavingsGoalEventType
            ? $existing->type
            : SavingsGoalEventType::from((string) $existing->type);

        $matches = (int) $existing->savings_goal_id === $goalId
            && $existingType === $type
            && (int) $existing->amount_minor === $amountMinor
            && $existing->note === $note;

        if (! $matches) {
            throw new IdempotencyConflictException(
                'Savings goal event idempotency key was reused with a different payload.',
            );
        }

        return new CreatedSavingsGoalEventResult(
            event: $existing->relationLoaded('currency')
                ? $existing
                : ($existing->fresh(['currency', 'savingsGoal']) ?? $existing),
            wasRecentlyCreated: false,
        );
    }

    private function sumNetAllocatedMinorForGoal(int $goalId): int
    {
        $amounts = SavingsGoalEvent::query()
            ->where('savings_goal_id', $goalId)
            ->orderBy('id')
            ->get(['type', 'amount_minor']);

        return SavingsGoalEventAggregation::netAllocatedMinor($amounts);
    }

    private function lockOwnedGoal(User $user, int $goalId): SavingsGoal
    {
        $goal = SavingsGoal::query()
            ->whereKey($goalId)
            ->lockForUpdate()
            ->first();

        if ($goal === null || (int) $goal->user_id !== (int) $user->id) {
            throw new LedgerIntegrityException('Savings goal is not accessible for this user.');
        }

        return $goal;
    }

    private function findOwnedGoal(User $user, int $goalId): SavingsGoal
    {
        $goal = SavingsGoal::query()
            ->whereKey($goalId)
            ->where('user_id', $user->id)
            ->first();

        if ($goal === null) {
            throw new LedgerIntegrityException('Savings goal is not accessible for this user.');
        }

        return $goal;
    }

    private function assertValidUser(User $user): void
    {
        if ($user->id === null) {
            throw new LedgerIntegrityException('A persisted user is required.');
        }
    }

    private function validateUuidIdempotencyKey(string $idempotencyKey): string
    {
        $normalized = strtolower(trim($idempotencyKey));

        if (! Str::isUuid($normalized)) {
            throw new LedgerIntegrityException('Savings goal event idempotency key must be a valid UUID.');
        }

        return $normalized;
    }

    private function validateAmountMinor(mixed $amountMinor): int
    {
        if (! is_int($amountMinor)) {
            throw new LedgerIntegrityException(
                'Savings allocation amount must be provided as an integer minor-unit amount.',
            );
        }

        if ($amountMinor <= 0 || $amountMinor > Money::MAX_MINOR_AMOUNT) {
            throw new LedgerIntegrityException(
                'Savings allocation amount must be a positive integer within supported bounds.',
            );
        }

        return $amountMinor;
    }

    private function normalizeNote(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }

        $trimmed = trim($note);
        if ($trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed) > 500) {
            throw new LedgerIntegrityException('Savings allocation note may not exceed 500 characters.');
        }

        return $trimmed;
    }

    private function isIdempotencyUniqueViolation(QueryException|UniqueConstraintViolationException $exception): bool
    {
        return IdempotencyUniqueConstraint::matches(
            exception: $exception,
            indexName: self::IDEMPOTENCY_UNIQUE_INDEX,
            tableName: 'savings_goal_events',
        );
    }
}
