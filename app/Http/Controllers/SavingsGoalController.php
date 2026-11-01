<?php

namespace App\Http\Controllers;

use App\DataTransferObjects\AllocateSavingsGoalInput;
use App\DataTransferObjects\CreatedSavingsGoalEventResult;
use App\DataTransferObjects\CreateSavingsGoalInput;
use App\DataTransferObjects\SavingsGoalCurrencyPortfolioTotals;
use App\DataTransferObjects\SavingsGoalPortfolioSummary;
use App\DataTransferObjects\SavingsGoalProgressSummary;
use App\DataTransferObjects\UpdateSavingsGoalInput;
use App\Enums\SavingsGoalEventType;
use App\Enums\SavingsGoalStatus;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\LedgerIntegrityException;
use App\Http\Requests\SavingsGoals\ArchiveSavingsGoalRequest;
use App\Http\Requests\SavingsGoals\IndexSavingsGoalEventRequest;
use App\Http\Requests\SavingsGoals\IndexSavingsGoalRequest;
use App\Http\Requests\SavingsGoals\RestoreSavingsGoalRequest;
use App\Http\Requests\SavingsGoals\ShowSavingsGoalPortfolioRequest;
use App\Http\Requests\SavingsGoals\ShowSavingsGoalRequest;
use App\Http\Requests\SavingsGoals\StoreSavingsGoalContributionRequest;
use App\Http\Requests\SavingsGoals\StoreSavingsGoalRequest;
use App\Http\Requests\SavingsGoals\StoreSavingsGoalWithdrawalRequest;
use App\Http\Requests\SavingsGoals\UpdateSavingsGoalRequest;
use App\Models\SavingsGoalEvent;
use App\Models\User;
use App\Rules\PositiveMinorAmountString;
use App\Services\SavingsGoalAllocationService;
use App\Services\SavingsGoalProgressService;
use App\Services\SavingsGoalService;
use App\Support\MoneyJson;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class SavingsGoalController extends Controller
{
    public const DEFAULT_PER_PAGE = 25;

    /**
     * Exact domain message for the archived-update business rule (single throw site).
     * Matched by full equality only — not substring classification.
     */
    private const ARCHIVED_UPDATE_MESSAGE = 'Archived savings goals cannot be edited until restored.';

    private const ARCHIVED_ALLOCATION_MESSAGE = 'Archived savings goals cannot accept new allocations.';

    private const WITHDRAWAL_EXCEEDS_MESSAGE = 'Withdrawal exceeds the current net allocated amount.';

    private const IDEMPOTENCY_CONFLICT_MESSAGE = 'The idempotency key was already used with different request data.';

    private const GENERIC_MUTATION_FAILURE_MESSAGE = 'Unable to process savings goal.';

    public function __construct(
        private readonly SavingsGoalProgressService $progressService,
        private readonly SavingsGoalAllocationService $allocationService,
        private readonly SavingsGoalService $lifecycleService,
    ) {}

    /**
     * Paginated progress summaries for the authenticated user's savings goals.
     */
    public function index(IndexSavingsGoalRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $status = isset($validated['status'])
            ? SavingsGoalStatus::from((string) $validated['status'])
            : null;
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE);

        try {
            $result = $this->progressService->getGoalSummaries(
                user: $request->user(),
                status: $status,
                page: $page,
                perPage: $perPage,
            );

            $items = $result->summaries
                ->map(fn (SavingsGoalProgressSummary $summary): array => $this->serializeGoalProgress($summary))
                ->values()
                ->all();
        } catch (LedgerIntegrityException) {
            return response()->json([
                'message' => 'Unable to load savings goals.',
            ], 500);
        }

        return response()->json([
            'data' => $items,
            'meta' => $this->serializePaginationMeta(
                currentPage: $result->currentPage,
                lastPage: $result->lastPage,
                perPage: $result->perPage,
                total: $result->total,
                itemCount: count($items),
            ),
            'filters' => [
                'status' => $status?->value,
            ],
        ]);
    }

    /**
     * Progress summary for a single owner-scoped savings goal.
     */
    public function show(ShowSavingsGoalRequest $request, int $goal): JsonResponse
    {
        try {
            $summary = $this->progressService->getGoalSummary($request->user(), $goal);

            return response()->json([
                'data' => $this->serializeGoalProgress($summary),
            ]);
        } catch (LedgerIntegrityException) {
            return response()->json([
                'message' => 'Unable to load savings goal.',
            ], 500);
        }
    }

    /**
     * Per-currency portfolio totals for the authenticated user.
     *
     * Aggregate overflow is an internal integrity failure and returns HTTP 500.
     */
    public function portfolio(ShowSavingsGoalPortfolioRequest $request): JsonResponse
    {
        try {
            $summary = $this->progressService->getPortfolioSummary($request->user());
        } catch (LedgerIntegrityException) {
            return response()->json([
                'message' => 'Unable to compute savings portfolio summary.',
            ], 500);
        }

        return response()->json([
            'data' => $this->serializePortfolio($summary),
        ]);
    }

    /**
     * Paginated allocation event history for an owner-scoped savings goal.
     */
    public function events(IndexSavingsGoalEventRequest $request, int $goal): JsonResponse
    {
        $validated = $request->validated();
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE);

        try {
            $result = $this->allocationService->getEvents(
                user: $request->user(),
                goalId: $goal,
                page: $page,
                perPage: $perPage,
            );

            $items = $result->events
                ->map(fn (SavingsGoalEvent $event): array => $this->serializeEvent($event))
                ->values()
                ->all();
        } catch (LedgerIntegrityException) {
            return response()->json([
                'message' => 'Unable to load savings goal events.',
            ], 500);
        }

        return response()->json([
            'data' => $items,
            'meta' => $this->serializePaginationMeta(
                currentPage: $result->currentPage,
                lastPage: $result->lastPage,
                perPage: $result->perPage,
                total: $result->total,
                itemCount: count($items),
            ),
        ]);
    }

    /**
     * Create a savings goal (or replay an identical idempotent request).
     */
    public function store(StoreSavingsGoalRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $result = $this->lifecycleService->createGoal(new CreateSavingsGoalInput(
                user: $request->user(),
                idempotencyKey: (string) $validated['idempotency_key'],
                name: (string) $validated['name'],
                currencyCode: (string) $validated['currency_code'],
                targetAmountMinor: PositiveMinorAmountString::toInt((string) $validated['target_amount_minor']),
                description: array_key_exists('description', $validated)
                    ? ($validated['description'] !== null ? (string) $validated['description'] : null)
                    : null,
                targetDate: array_key_exists('target_date', $validated)
                    ? ($validated['target_date'] !== null ? (string) $validated['target_date'] : null)
                    : null,
            ));
        } catch (IdempotencyConflictException) {
            return response()->json([
                'message' => self::IDEMPOTENCY_CONFLICT_MESSAGE,
                'errors' => [
                    'idempotency_key' => [self::IDEMPOTENCY_CONFLICT_MESSAGE],
                ],
            ], 409);
        } catch (LedgerIntegrityException) {
            return $this->genericMutationFailureResponse();
        }

        return $this->progressMutationResponse(
            user: $request->user(),
            goalId: (int) $result->goal->id,
            status: $result->wasRecentlyCreated ? 201 : 200,
            meta: [
                'was_recently_created' => $result->wasRecentlyCreated,
                'idempotent_replay' => ! $result->wasRecentlyCreated,
            ],
        );
    }

    /**
     * Update mutable fields on an owner-scoped active savings goal.
     */
    public function update(UpdateSavingsGoalRequest $request, int $goal): JsonResponse
    {
        $validated = $request->validated();
        $clearDescription = array_key_exists('description', $validated) && $validated['description'] === null;
        $clearTargetDate = array_key_exists('target_date', $validated) && $validated['target_date'] === null;

        try {
            $this->lifecycleService->updateGoal(new UpdateSavingsGoalInput(
                user: $request->user(),
                goalId: $goal,
                name: (string) $validated['name'],
                targetAmountMinor: PositiveMinorAmountString::toInt((string) $validated['target_amount_minor']),
                description: ($clearDescription || ! array_key_exists('description', $validated))
                    ? null
                    : (string) $validated['description'],
                clearDescription: $clearDescription,
                targetDate: ($clearTargetDate || ! array_key_exists('target_date', $validated))
                    ? null
                    : (string) $validated['target_date'],
                clearTargetDate: $clearTargetDate,
            ));
        } catch (LedgerIntegrityException $exception) {
            if ($exception->getMessage() === self::ARCHIVED_UPDATE_MESSAGE) {
                throw ValidationException::withMessages([
                    'goal' => [self::ARCHIVED_UPDATE_MESSAGE],
                ]);
            }

            return $this->genericMutationFailureResponse();
        }

        return $this->progressMutationResponse(
            user: $request->user(),
            goalId: $goal,
        );
    }

    /**
     * Archive an owner-scoped savings goal (idempotent).
     */
    public function archive(ArchiveSavingsGoalRequest $request, int $goal): JsonResponse
    {
        try {
            $this->lifecycleService->archiveGoal($request->user(), $goal);
        } catch (LedgerIntegrityException) {
            return $this->genericMutationFailureResponse();
        }

        return $this->progressMutationResponse(
            user: $request->user(),
            goalId: $goal,
        );
    }

    /**
     * Restore an owner-scoped archived savings goal (idempotent).
     */
    public function restore(RestoreSavingsGoalRequest $request, int $goal): JsonResponse
    {
        try {
            $this->lifecycleService->restoreGoal($request->user(), $goal);
        } catch (LedgerIntegrityException) {
            return $this->genericMutationFailureResponse();
        }

        return $this->progressMutationResponse(
            user: $request->user(),
            goalId: $goal,
        );
    }

    /**
     * Contribute an earmarked amount to an owner-scoped savings goal.
     */
    public function contribute(StoreSavingsGoalContributionRequest $request, int $goal): JsonResponse
    {
        return $this->storeAllocation($request, $goal, contribute: true);
    }

    /**
     * Withdraw an earmarked amount from an owner-scoped savings goal.
     */
    public function withdraw(StoreSavingsGoalWithdrawalRequest $request, int $goal): JsonResponse
    {
        return $this->storeAllocation($request, $goal, contribute: false);
    }

    private function storeAllocation(
        StoreSavingsGoalContributionRequest|StoreSavingsGoalWithdrawalRequest $request,
        int $goal,
        bool $contribute,
    ): JsonResponse {
        $validated = $request->validated();

        $input = new AllocateSavingsGoalInput(
            user: $request->user(),
            goalId: $goal,
            idempotencyKey: (string) $validated['idempotency_key'],
            amountMinor: PositiveMinorAmountString::toInt((string) $validated['amount_minor']),
            note: array_key_exists('note', $validated)
                ? ($validated['note'] !== null ? (string) $validated['note'] : null)
                : null,
        );

        try {
            $result = $contribute
                ? $this->allocationService->contribute($input)
                : $this->allocationService->withdraw($input);
        } catch (IdempotencyConflictException) {
            return response()->json([
                'message' => self::IDEMPOTENCY_CONFLICT_MESSAGE,
                'errors' => [
                    'idempotency_key' => [self::IDEMPOTENCY_CONFLICT_MESSAGE],
                ],
            ], 409);
        } catch (LedgerIntegrityException $exception) {
            return $this->mapAllocationIntegrityException($exception);
        }

        return $this->allocationMutationResponse(
            user: $request->user(),
            goalId: $goal,
            result: $result,
        );
    }

    /**
     * Load progress after a committed allocation and return event + goal payloads.
     */
    private function allocationMutationResponse(
        User $user,
        int $goalId,
        CreatedSavingsGoalEventResult $result,
    ): JsonResponse {
        try {
            $summary = $this->progressService->getGoalSummary($user, $goalId);

            return response()->json([
                'data' => [
                    'event' => $this->serializeEvent($result->event),
                    'goal' => $this->serializeGoalProgress($summary),
                ],
                'meta' => [
                    'was_recently_created' => $result->wasRecentlyCreated,
                    'idempotent_replay' => ! $result->wasRecentlyCreated,
                ],
            ], $result->wasRecentlyCreated ? 201 : 200);
        } catch (LedgerIntegrityException) {
            return $this->genericMutationFailureResponse();
        }
    }

    private function mapAllocationIntegrityException(LedgerIntegrityException $exception): JsonResponse
    {
        $message = $exception->getMessage();

        if ($message === self::ARCHIVED_ALLOCATION_MESSAGE) {
            throw ValidationException::withMessages([
                'goal' => [self::ARCHIVED_ALLOCATION_MESSAGE],
            ]);
        }

        if ($message === self::WITHDRAWAL_EXCEEDS_MESSAGE) {
            throw ValidationException::withMessages([
                'amount_minor' => [self::WITHDRAWAL_EXCEEDS_MESSAGE],
            ]);
        }

        return $this->genericMutationFailureResponse();
    }

    /**
     * Load and serialize progress after a committed lifecycle mutation.
     *
     * Progress/serialization failures never present as input validation errors.
     *
     * @param  array<string, mixed>|null  $meta
     */
    private function progressMutationResponse(
        User $user,
        int $goalId,
        int $status = 200,
        ?array $meta = null,
    ): JsonResponse {
        try {
            $summary = $this->progressService->getGoalSummary($user, $goalId);
            $payload = [
                'data' => $this->serializeGoalProgress($summary),
            ];

            if ($meta !== null) {
                $payload['meta'] = $meta;
            }

            return response()->json($payload, $status);
        } catch (LedgerIntegrityException) {
            return $this->genericMutationFailureResponse();
        }
    }

    private function genericMutationFailureResponse(): JsonResponse
    {
        return response()->json([
            'message' => self::GENERIC_MUTATION_FAILURE_MESSAGE,
        ], 500);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeGoalProgress(SavingsGoalProgressSummary $summary): array
    {
        return [
            'id' => $summary->goalId,
            'name' => $summary->name,
            'description' => $summary->description,
            'currency_code' => $summary->currencyCode,
            'status' => $summary->status->value,
            'target_amount_minor' => MoneyJson::encodeMinor($summary->targetAmountMinor),
            'allocated_amount_minor' => MoneyJson::encodeMinor($summary->allocatedAmountMinor),
            'remaining_amount_minor' => MoneyJson::encodeMinor($summary->remainingAmountMinor),
            'overfunded_amount_minor' => MoneyJson::encodeMinor($summary->overfundedAmountMinor),
            'progress_basis_points' => $summary->progressBasisPoints,
            'target_reached' => $summary->targetReached,
            'target_date' => $summary->targetDate,
            'archived_at' => $this->formatNullableTimestamp($summary->archivedAt, 'archived_at'),
            'created_at' => $this->formatRequiredTimestamp($summary->createdAt, 'created_at'),
            'updated_at' => $this->formatRequiredTimestamp($summary->updatedAt, 'updated_at'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEvent(SavingsGoalEvent $event): array
    {
        $type = $event->type instanceof SavingsGoalEventType
            ? $event->type->value
            : (string) $event->type;

        return [
            'id' => (int) $event->id,
            'savings_goal_id' => (int) $event->savings_goal_id,
            'type' => $type,
            'amount_minor' => MoneyJson::encodeMinor((int) $event->amount_minor),
            'currency_code' => (string) $event->currency_code,
            'note' => $event->note !== null ? (string) $event->note : null,
            'created_at' => $this->formatRequiredTimestamp($event->created_at, 'created_at'),
            'updated_at' => $this->formatRequiredTimestamp($event->updated_at, 'updated_at'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePortfolio(SavingsGoalPortfolioSummary $summary): array
    {
        return [
            'total_goal_count' => $summary->totalGoalCount,
            'active_goal_count' => $summary->activeGoalCount,
            'archived_goal_count' => $summary->archivedGoalCount,
            'active_at_target_count' => $summary->activeAtTargetCount,
            'active_below_target_count' => $summary->activeBelowTargetCount,
            'currencies' => array_map(
                fn (SavingsGoalCurrencyPortfolioTotals $row): array => [
                    'currency_code' => $row->currencyCode,
                    'active_goal_count' => $row->activeGoalCount,
                    'total_target_minor' => MoneyJson::encodeMinor($row->totalTargetMinor),
                    'total_allocated_minor' => MoneyJson::encodeMinor($row->totalAllocatedMinor),
                    'total_remaining_minor' => MoneyJson::encodeMinor($row->totalRemainingMinor),
                    'total_overfunded_minor' => MoneyJson::encodeMinor($row->totalOverfundedMinor),
                ],
                $summary->currencies,
            ),
        ];
    }

    /**
     * @return array{
     *     current_page: int,
     *     last_page: int,
     *     per_page: int,
     *     total: int,
     *     from: int|null,
     *     to: int|null
     * }
     */
    private function serializePaginationMeta(
        int $currentPage,
        int $lastPage,
        int $perPage,
        int $total,
        int $itemCount,
    ): array {
        $from = $itemCount > 0 ? (($currentPage - 1) * $perPage) + 1 : null;
        $to = $itemCount > 0 ? $from + $itemCount - 1 : null;

        return [
            'current_page' => $currentPage,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'total' => $total,
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * Serialize a required timestamp. Missing or unsupported values fail closed.
     */
    private function formatRequiredTimestamp(mixed $value, string $attribute): string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        throw new LedgerIntegrityException(
            "Savings {$attribute} timestamp is required.",
        );
    }

    /**
     * Serialize an optional timestamp. Null remains null; non-null unsupported values fail closed.
     */
    private function formatNullableTimestamp(mixed $value, string $attribute): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        throw new LedgerIntegrityException(
            "Savings {$attribute} timestamp is invalid.",
        );
    }
}
