<?php

namespace App\Services;

use App\DataTransferObjects\SavingsGoalCurrencyPortfolioTotals;
use App\DataTransferObjects\SavingsGoalPortfolioSummary;
use App\DataTransferObjects\SavingsGoalProgressListResult;
use App\DataTransferObjects\SavingsGoalProgressSummary;
use App\Enums\SavingsGoalStatus;
use App\Exceptions\LedgerIntegrityException;
use App\Models\SavingsGoal;
use App\Models\SavingsGoalEvent;
use App\Models\User;
use App\Support\Money;
use App\Support\SavingsGoalEventAggregation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SavingsGoalProgressService
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    /**
     * Displayed progress scale: 10000 basis points = 100.00%.
     */
    public const PROGRESS_FULL_BASIS_POINTS = 10_000;

    public function getGoalSummary(User $user, int $goalId): SavingsGoalProgressSummary
    {
        $this->assertValidUser($user);

        $goal = SavingsGoal::query()
            ->whereKey($goalId)
            ->where('user_id', $user->id)
            ->first();

        if ($goal === null) {
            throw new LedgerIntegrityException('Savings goal is not accessible for this user.');
        }

        $events = SavingsGoalEvent::query()
            ->where('user_id', $user->id)
            ->where('savings_goal_id', $goal->id)
            ->orderBy('id')
            ->get(['type', 'amount_minor']);

        $allocated = SavingsGoalEventAggregation::netAllocatedMinor($events);

        return $this->buildSummary($goal, $allocated);
    }

    public function getGoalSummaries(
        User $user,
        ?SavingsGoalStatus $status = null,
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE,
    ): SavingsGoalProgressListResult {
        $this->assertValidUser($user);

        $page = max(1, $page);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));

        $query = SavingsGoal::query()
            ->where('user_id', $user->id)
            ->orderBy('id');

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        $paginator = $query->paginate(perPage: $perPage, page: $page);
        /** @var Collection<int, SavingsGoal> $goals */
        $goals = collect($paginator->items());

        $nets = $this->netsForGoals($user, $goals->pluck('id')->map(fn ($id): int => (int) $id)->all());

        $summaries = $goals->map(
            fn (SavingsGoal $goal): SavingsGoalProgressSummary => $this->buildSummary(
                $goal,
                $nets[(int) $goal->id] ?? 0,
            ),
        )->values();

        return new SavingsGoalProgressListResult(
            summaries: $summaries,
            total: $paginator->total(),
            perPage: $paginator->perPage(),
            currentPage: $paginator->currentPage(),
            lastPage: $paginator->lastPage(),
        );
    }

    public function getPortfolioSummary(User $user): SavingsGoalPortfolioSummary
    {
        $this->assertValidUser($user);

        $goals = SavingsGoal::query()
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get();

        $activeGoals = $goals->filter(
            fn (SavingsGoal $goal): bool => $goal->status === SavingsGoalStatus::Active,
        )->values();

        $archivedGoalCount = $goals->filter(
            fn (SavingsGoal $goal): bool => $goal->status === SavingsGoalStatus::Archived,
        )->count();

        $nets = $this->netsForGoals(
            $user,
            $activeGoals->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        );

        $activeAtTargetCount = 0;
        $activeBelowTargetCount = 0;

        /** @var array<string, array{count: int, target: int, allocated: int, remaining: int, overfunded: int}> $buckets */
        $buckets = [];

        foreach ($activeGoals as $goal) {
            $allocated = $nets[(int) $goal->id] ?? 0;
            $target = (int) $goal->target_amount_minor;
            $derived = $this->deriveProgressFields($target, $allocated);

            if ($derived['targetReached']) {
                $activeAtTargetCount++;
            } else {
                $activeBelowTargetCount++;
            }

            $currency = (string) $goal->currency_code;
            if (! isset($buckets[$currency])) {
                $buckets[$currency] = [
                    'count' => 0,
                    'target' => 0,
                    'allocated' => 0,
                    'remaining' => 0,
                    'overfunded' => 0,
                ];
            }

            $buckets[$currency]['count']++;
            $buckets[$currency]['target'] = Money::checkedAdd($buckets[$currency]['target'], $target);
            $buckets[$currency]['allocated'] = Money::checkedAdd($buckets[$currency]['allocated'], $allocated);
            $buckets[$currency]['remaining'] = Money::checkedAdd(
                $buckets[$currency]['remaining'],
                $derived['remainingAmountMinor'],
            );
            $buckets[$currency]['overfunded'] = Money::checkedAdd(
                $buckets[$currency]['overfunded'],
                $derived['overfundedAmountMinor'],
            );
        }

        ksort($buckets, SORT_STRING);

        $currencies = [];
        foreach ($buckets as $currencyCode => $bucket) {
            $currencies[] = new SavingsGoalCurrencyPortfolioTotals(
                currencyCode: $currencyCode,
                activeGoalCount: $bucket['count'],
                totalTargetMinor: $bucket['target'],
                totalAllocatedMinor: $bucket['allocated'],
                totalRemainingMinor: $bucket['remaining'],
                totalOverfundedMinor: $bucket['overfunded'],
            );
        }

        $activeGoalCount = $activeGoals->count();

        return new SavingsGoalPortfolioSummary(
            totalGoalCount: $goals->count(),
            activeGoalCount: $activeGoalCount,
            archivedGoalCount: $archivedGoalCount,
            activeAtTargetCount: $activeAtTargetCount,
            activeBelowTargetCount: $activeBelowTargetCount,
            currencies: $currencies,
        );
    }

    /**
     * @param  list<int>  $goalIds
     * @return array<int, int> goalId => allocated minor
     */
    private function netsForGoals(User $user, array $goalIds): array
    {
        $goalIds = array_values(array_unique(array_map(static fn (int $id): int => $id, $goalIds)));

        if ($goalIds === []) {
            return [];
        }

        $events = SavingsGoalEvent::query()
            ->where('user_id', $user->id)
            ->whereIn('savings_goal_id', $goalIds)
            ->orderBy('id')
            ->get(['savings_goal_id', 'type', 'amount_minor']);

        /** @var array<int, list<object>> $grouped */
        $grouped = [];
        foreach ($goalIds as $goalId) {
            $grouped[$goalId] = [];
        }

        foreach ($events as $event) {
            $grouped[(int) $event->savings_goal_id][] = $event;
        }

        $nets = [];
        foreach ($grouped as $goalId => $goalEvents) {
            $nets[$goalId] = SavingsGoalEventAggregation::netAllocatedMinor($goalEvents);
        }

        return $nets;
    }

    private function buildSummary(SavingsGoal $goal, int $allocatedAmountMinor): SavingsGoalProgressSummary
    {
        $target = (int) $goal->target_amount_minor;
        $derived = $this->deriveProgressFields($target, $allocatedAmountMinor);

        $status = $goal->status instanceof SavingsGoalStatus
            ? $goal->status
            : SavingsGoalStatus::from((string) $goal->status);

        $targetDate = $goal->target_date;
        $targetDateString = $targetDate instanceof Carbon
            ? $targetDate->toDateString()
            : ($targetDate !== null ? (string) $targetDate : null);

        return new SavingsGoalProgressSummary(
            goalId: (int) $goal->id,
            name: (string) $goal->name,
            description: $goal->description !== null ? (string) $goal->description : null,
            currencyCode: (string) $goal->currency_code,
            status: $status,
            targetAmountMinor: $target,
            allocatedAmountMinor: $allocatedAmountMinor,
            remainingAmountMinor: $derived['remainingAmountMinor'],
            overfundedAmountMinor: $derived['overfundedAmountMinor'],
            progressBasisPoints: $derived['progressBasisPoints'],
            targetReached: $derived['targetReached'],
            targetDate: $targetDateString,
            archivedAt: $goal->archived_at instanceof Carbon ? $goal->archived_at->copy() : null,
            createdAt: $this->requireTimestamp($goal->created_at, 'created_at')->copy(),
            updatedAt: $this->requireTimestamp($goal->updated_at, 'updated_at')->copy(),
        );
    }

    private function requireTimestamp(mixed $value, string $attribute): Carbon
    {
        if (! $value instanceof Carbon) {
            throw new LedgerIntegrityException(
                "Savings goal {$attribute} timestamp is required.",
            );
        }

        return $value;
    }

    /**
     * @return array{
     *     remainingAmountMinor: int,
     *     overfundedAmountMinor: int,
     *     progressBasisPoints: int,
     *     targetReached: bool
     * }
     */
    private function deriveProgressFields(int $targetAmountMinor, int $allocatedAmountMinor): array
    {
        if ($targetAmountMinor <= 0 || $targetAmountMinor > Money::MAX_MINOR_AMOUNT) {
            throw new LedgerIntegrityException(
                'Savings goal target must be a positive integer within supported bounds.',
            );
        }

        if ($allocatedAmountMinor < 0 || $allocatedAmountMinor > Money::MAX_MINOR_AMOUNT) {
            throw new LedgerIntegrityException(
                'Savings goal allocated amount must be within supported bounds.',
            );
        }

        $targetReached = $allocatedAmountMinor >= $targetAmountMinor;

        if ($allocatedAmountMinor <= $targetAmountMinor) {
            $remainingAmountMinor = Money::checkedSubtract($targetAmountMinor, $allocatedAmountMinor);
            $overfundedAmountMinor = 0;
        } else {
            $remainingAmountMinor = 0;
            $overfundedAmountMinor = Money::checkedSubtract($allocatedAmountMinor, $targetAmountMinor);
        }

        return [
            'remainingAmountMinor' => $remainingAmountMinor,
            'overfundedAmountMinor' => $overfundedAmountMinor,
            'progressBasisPoints' => $this->progressBasisPoints($allocatedAmountMinor, $targetAmountMinor),
            'targetReached' => $targetReached,
        ];
    }

    /**
     * Display progress in basis points, floored and capped at 10000.
     *
     * floor(allocated * 10000 / target) equals 10000 iff allocated >= target, so
     * floor rounding never reports full progress while still below target.
     */
    private function progressBasisPoints(int $allocatedAmountMinor, int $targetAmountMinor): int
    {
        if ($allocatedAmountMinor <= 0) {
            return 0;
        }

        if ($allocatedAmountMinor >= $targetAmountMinor) {
            return self::PROGRESS_FULL_BASIS_POINTS;
        }

        return Money::checkedMulDivFloor(
            $allocatedAmountMinor,
            self::PROGRESS_FULL_BASIS_POINTS,
            $targetAmountMinor,
        );
    }

    private function assertValidUser(User $user): void
    {
        if ($user->id === null) {
            throw new LedgerIntegrityException('A persisted user is required.');
        }
    }
}
