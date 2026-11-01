<?php

namespace App\Http\Controllers;

use App\DataTransferObjects\SavingsGoalCurrencyPortfolioTotals;
use App\DataTransferObjects\SavingsGoalPortfolioSummary;
use App\DataTransferObjects\SavingsGoalProgressSummary;
use App\Enums\SavingsGoalEventType;
use App\Enums\SavingsGoalStatus;
use App\Exceptions\LedgerIntegrityException;
use App\Http\Requests\SavingsGoals\IndexSavingsGoalEventRequest;
use App\Http\Requests\SavingsGoals\IndexSavingsGoalRequest;
use App\Http\Requests\SavingsGoals\ShowSavingsGoalPortfolioRequest;
use App\Http\Requests\SavingsGoals\ShowSavingsGoalRequest;
use App\Models\SavingsGoalEvent;
use App\Services\SavingsGoalAllocationService;
use App\Services\SavingsGoalProgressService;
use App\Support\MoneyJson;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;

class SavingsGoalController extends Controller
{
    public const DEFAULT_PER_PAGE = 25;

    public function __construct(
        private readonly SavingsGoalProgressService $progressService,
        private readonly SavingsGoalAllocationService $allocationService,
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
