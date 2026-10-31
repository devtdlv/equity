<?php

namespace App\Services;

use App\DataTransferObjects\CreatedSavingsGoalResult;
use App\DataTransferObjects\CreateSavingsGoalInput;
use App\DataTransferObjects\SavingsGoalListResult;
use App\DataTransferObjects\UpdateSavingsGoalInput;
use App\Enums\SavingsGoalStatus;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\LedgerIntegrityException;
use App\Models\Currency;
use App\Models\SavingsGoal;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SavingsGoalService
{
    public const IDEMPOTENCY_UNIQUE_INDEX = 'savings_goals_user_id_idempotency_key_unique';

    public const MAX_CONCURRENCY_ATTEMPTS = 3;

    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    public function createGoal(CreateSavingsGoalInput $input): CreatedSavingsGoalResult
    {
        $this->assertValidUser($input->user);

        $idempotencyKey = $this->validateUuidIdempotencyKey($input->idempotencyKey);
        $name = $this->validateName($input->name);
        $description = $this->normalizeDescription($input->description);
        $currency = $this->resolveActiveCurrency($input->currencyCode);
        $targetAmountMinor = $this->validateTargetAmountMinor($input->targetAmountMinor);
        $targetDate = $this->normalizeTargetDate($input->targetDate);
        $fingerprint = $this->createRequestFingerprint(
            name: $name,
            description: $description,
            currencyCode: $currency->code,
            targetAmountMinor: $targetAmountMinor,
            targetDate: $targetDate,
        );

        for ($attempt = 1; $attempt <= self::MAX_CONCURRENCY_ATTEMPTS; $attempt++) {
            $existing = SavingsGoal::query()
                ->where('user_id', $input->user->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $this->verifyExistingCreateMatch($existing, $fingerprint);
            }

            try {
                return DB::transaction(function () use (
                    $input,
                    $idempotencyKey,
                    $name,
                    $description,
                    $currency,
                    $targetAmountMinor,
                    $targetDate,
                    $fingerprint,
                ): CreatedSavingsGoalResult {
                    $lockedExisting = SavingsGoal::query()
                        ->where('user_id', $input->user->id)
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($lockedExisting !== null) {
                        return $this->verifyExistingCreateMatch($lockedExisting, $fingerprint);
                    }

                    $goal = SavingsGoal::query()->create([
                        'user_id' => $input->user->id,
                        'idempotency_key' => $idempotencyKey,
                        'create_request_fingerprint' => $fingerprint,
                        'name' => $name,
                        'description' => $description,
                        'currency_code' => $currency->code,
                        'target_amount_minor' => $targetAmountMinor,
                        'target_date' => $targetDate,
                        'status' => SavingsGoalStatus::Active,
                        'archived_at' => null,
                    ]);

                    $this->auditService->record(
                        actor: $input->user,
                        action: AuditService::ACTION_SAVINGS_GOAL_CREATED,
                        auditableType: SavingsGoal::class,
                        auditableId: (int) $goal->id,
                        metadata: [
                            'name' => $goal->name,
                            'currency_code' => $goal->currency_code,
                            'target_amount_minor' => (int) $goal->target_amount_minor,
                            'target_date' => $goal->target_date?->toDateString(),
                            'idempotency_key' => $idempotencyKey,
                        ],
                    );

                    return new CreatedSavingsGoalResult(
                        goal: $goal->fresh(['currency']) ?? $goal,
                        wasRecentlyCreated: true,
                    );
                });
            } catch (UniqueConstraintViolationException $exception) {
                if (! $this->isIdempotencyUniqueViolation($exception)) {
                    throw $exception;
                }

                $raced = SavingsGoal::query()
                    ->where('user_id', $input->user->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($raced !== null) {
                    return $this->verifyExistingCreateMatch($raced, $fingerprint);
                }
            } catch (QueryException $exception) {
                if (! $this->isIdempotencyUniqueViolation($exception)) {
                    throw $exception;
                }

                $raced = SavingsGoal::query()
                    ->where('user_id', $input->user->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($raced !== null) {
                    return $this->verifyExistingCreateMatch($raced, $fingerprint);
                }
            }
        }

        throw new LedgerIntegrityException(
            'Unable to create savings goal due to concurrent contention.',
        );
    }

    public function updateGoal(UpdateSavingsGoalInput $input): SavingsGoal
    {
        $this->assertValidUser($input->user);

        $name = $this->validateName($input->name);
        $targetAmountMinor = $this->validateTargetAmountMinor($input->targetAmountMinor);

        if ($input->clearDescription && $input->description !== null) {
            throw new LedgerIntegrityException(
                'Cannot clear and set savings goal description in the same request.',
            );
        }

        if ($input->clearTargetDate && $input->targetDate !== null) {
            throw new LedgerIntegrityException(
                'Cannot clear and set savings goal target date in the same request.',
            );
        }

        $descriptionProvided = $input->clearDescription || $input->description !== null;
        $description = $input->clearDescription
            ? null
            : ($input->description !== null ? $this->normalizeDescription($input->description) : null);

        $targetDateProvided = $input->clearTargetDate || $input->targetDate !== null;
        $targetDate = $input->clearTargetDate
            ? null
            : ($input->targetDate !== null ? $this->normalizeTargetDate($input->targetDate) : null);

        return DB::transaction(function () use (
            $input,
            $name,
            $descriptionProvided,
            $description,
            $targetAmountMinor,
            $targetDateProvided,
            $targetDate,
        ): SavingsGoal {
            $goal = $this->lockOwnedGoal($input->user, $input->goalId);

            if ($goal->status === SavingsGoalStatus::Archived) {
                throw new LedgerIntegrityException('Archived savings goals cannot be edited until restored.');
            }

            $before = $this->goalSnapshot($goal);

            $goal->name = $name;
            $goal->target_amount_minor = $targetAmountMinor;

            if ($descriptionProvided) {
                $goal->description = $description;
            }

            if ($targetDateProvided) {
                $goal->target_date = $targetDate;
            }

            $goal->save();

            $fresh = $goal->fresh(['currency']) ?? $goal;
            $after = $this->goalSnapshot($fresh);

            if ($before !== $after) {
                $this->auditService->record(
                    actor: $input->user,
                    action: AuditService::ACTION_SAVINGS_GOAL_UPDATED,
                    auditableType: SavingsGoal::class,
                    auditableId: (int) $fresh->id,
                    metadata: [
                        'before' => $before,
                        'after' => $after,
                    ],
                );
            }

            return $fresh;
        });
    }

    public function archiveGoal(User $user, int $goalId): SavingsGoal
    {
        $this->assertValidUser($user);

        return DB::transaction(function () use ($user, $goalId): SavingsGoal {
            $goal = $this->lockOwnedGoal($user, $goalId);

            if ($goal->status === SavingsGoalStatus::Archived) {
                return $goal->fresh(['currency']) ?? $goal;
            }

            $goal->status = SavingsGoalStatus::Archived;
            $goal->archived_at = now('UTC');
            $goal->save();

            $this->auditService->record(
                actor: $user,
                action: AuditService::ACTION_SAVINGS_GOAL_ARCHIVED,
                auditableType: SavingsGoal::class,
                auditableId: (int) $goal->id,
                metadata: [
                    'status' => SavingsGoalStatus::Archived->value,
                    'archived_at' => $goal->archived_at?->toIso8601String(),
                ],
            );

            return $goal->fresh(['currency']) ?? $goal;
        });
    }

    public function restoreGoal(User $user, int $goalId): SavingsGoal
    {
        $this->assertValidUser($user);

        return DB::transaction(function () use ($user, $goalId): SavingsGoal {
            $goal = $this->lockOwnedGoal($user, $goalId);

            if ($goal->status === SavingsGoalStatus::Active) {
                return $goal->fresh(['currency']) ?? $goal;
            }

            $goal->status = SavingsGoalStatus::Active;
            $goal->archived_at = null;
            $goal->save();

            $this->auditService->record(
                actor: $user,
                action: AuditService::ACTION_SAVINGS_GOAL_RESTORED,
                auditableType: SavingsGoal::class,
                auditableId: (int) $goal->id,
                metadata: [
                    'status' => SavingsGoalStatus::Active->value,
                ],
            );

            return $goal->fresh(['currency']) ?? $goal;
        });
    }

    public function getGoal(User $user, int $goalId): SavingsGoal
    {
        $this->assertValidUser($user);

        $goal = SavingsGoal::query()
            ->with('currency')
            ->whereKey($goalId)
            ->where('user_id', $user->id)
            ->first();

        if ($goal === null) {
            throw new LedgerIntegrityException('Savings goal is not accessible for this user.');
        }

        return $goal;
    }

    public function listGoals(
        User $user,
        ?SavingsGoalStatus $status = null,
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE,
    ): SavingsGoalListResult {
        $this->assertValidUser($user);

        $page = max(1, $page);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));

        $query = SavingsGoal::query()
            ->with('currency')
            ->where('user_id', $user->id)
            ->orderBy('id');

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        $paginator = $query->paginate(perPage: $perPage, page: $page);

        return new SavingsGoalListResult(
            goals: collect($paginator->items()),
            total: $paginator->total(),
            perPage: $paginator->perPage(),
            currentPage: $paginator->currentPage(),
            lastPage: $paginator->lastPage(),
        );
    }

    private function verifyExistingCreateMatch(
        SavingsGoal $existing,
        string $fingerprint,
    ): CreatedSavingsGoalResult {
        if (! hash_equals((string) $existing->create_request_fingerprint, $fingerprint)) {
            throw new IdempotencyConflictException(
                'Savings goal idempotency key was reused with a different payload.',
            );
        }

        return new CreatedSavingsGoalResult(
            goal: $existing->relationLoaded('currency')
                ? $existing
                : ($existing->fresh(['currency']) ?? $existing),
            wasRecentlyCreated: false,
        );
    }

    private function createRequestFingerprint(
        string $name,
        ?string $description,
        string $currencyCode,
        int $targetAmountMinor,
        ?string $targetDate,
    ): string {
        return hash('sha256', json_encode([
            'name' => $name,
            'description' => $description,
            'currency_code' => $currencyCode,
            'target_amount_minor' => $targetAmountMinor,
            'target_date' => $targetDate,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{name: string, description: ?string, target_amount_minor: int, target_date: ?string, status: string}
     */
    private function goalSnapshot(SavingsGoal $goal): array
    {
        return [
            'name' => $goal->name,
            'description' => $goal->description,
            'target_amount_minor' => (int) $goal->target_amount_minor,
            'target_date' => $goal->target_date?->toDateString(),
            'status' => $goal->status instanceof SavingsGoalStatus
                ? $goal->status->value
                : (string) $goal->status,
        ];
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
            throw new LedgerIntegrityException('Savings goal idempotency key must be a valid UUID.');
        }

        return $normalized;
    }

    private function validateName(string $name): string
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw new LedgerIntegrityException('Savings goal name is required.');
        }

        if (mb_strlen($trimmed) > 120) {
            throw new LedgerIntegrityException('Savings goal name may not exceed 120 characters.');
        }

        return $trimmed;
    }

    private function normalizeDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }

        $trimmed = trim($description);
        if ($trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed) > 500) {
            throw new LedgerIntegrityException('Savings goal description may not exceed 500 characters.');
        }

        return $trimmed;
    }

    private function validateTargetAmountMinor(mixed $targetAmountMinor): int
    {
        if (! is_int($targetAmountMinor)) {
            throw new LedgerIntegrityException(
                'Savings goal target must be provided as an integer minor-unit amount.',
            );
        }

        if ($targetAmountMinor <= 0 || $targetAmountMinor > Money::MAX_MINOR_AMOUNT) {
            throw new LedgerIntegrityException(
                'Savings goal target must be a positive integer within supported bounds.',
            );
        }

        return $targetAmountMinor;
    }

    private function normalizeTargetDate(?string $targetDate): ?string
    {
        if ($targetDate === null) {
            return null;
        }

        $trimmed = trim($targetDate);
        if ($trimmed === '') {
            return null;
        }

        if (! preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $trimmed)) {
            throw new LedgerIntegrityException('Savings goal target date must use Y-m-d format.');
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d', $trimmed, 'UTC');
        } catch (\Throwable) {
            throw new LedgerIntegrityException('Savings goal target date is invalid.');
        }

        if ($parsed === false || $parsed->format('Y-m-d') !== $trimmed) {
            throw new LedgerIntegrityException('Savings goal target date is invalid.');
        }

        return $parsed->toDateString();
    }

    private function resolveActiveCurrency(string $currencyCode): Currency
    {
        $normalized = strtoupper(trim($currencyCode));

        $currency = Currency::query()
            ->where('code', $normalized)
            ->first();

        if ($currency === null || ! $currency->is_active) {
            throw new LedgerIntegrityException('Currency is not available.');
        }

        return $currency;
    }

    private function isIdempotencyUniqueViolation(QueryException|UniqueConstraintViolationException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, strtolower(self::IDEMPOTENCY_UNIQUE_INDEX))
            || str_contains($message, 'idempotency_key');
    }
}
