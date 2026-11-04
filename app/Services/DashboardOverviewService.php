<?php

namespace App\Services;

use App\DataTransferObjects\BudgetSpendingSummary;
use App\DataTransferObjects\DashboardOverview;
use App\DataTransferObjects\SavingsGoalCurrencyPortfolioTotals;
use App\DataTransferObjects\SavingsGoalProgressSummary;
use App\Enums\FinancialTransactionType;
use App\Enums\RecurringOccurrenceStatus;
use App\Enums\SavingsGoalStatus;
use App\Exceptions\LedgerIntegrityException;
use App\Models\Budget;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\RecurringOccurrence;
use App\Models\User;
use App\Support\Money;
use App\Support\MoneyJson;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Composes a read-only dashboard overview from existing domain services.
 * Does not post financial mutations.
 */
final class DashboardOverviewService
{
    public const BILLS_HORIZON_DAYS = 14;

    public const BILLS_DISPLAY_CAP = 8;

    public const SAVINGS_GOALS_DISPLAY_CAP = 8;

    private const EARMARK_NOTE = 'Savings goal allocations are earmarks. They do not transfer money or change financial account balances.';

    private const ZERO_MINOR = '0';

    public function __construct(
        private readonly AccountBalanceService $balanceService,
        private readonly BudgetService $budgetService,
        private readonly RecurringPaymentService $paymentService,
        private readonly SavingsGoalProgressService $savingsProgressService,
    ) {}

    public function build(User $user): DashboardOverview
    {
        if ($user->id === null) {
            throw new LedgerIntegrityException('A persisted user is required for the dashboard overview.');
        }

        $now = Carbon::now('UTC');
        $periodStart = $now->copy()->startOfMonth()->toDateString();
        $periodEnd = $now->copy()->endOfMonth()->toDateString();
        $today = $now->toDateString();
        $horizonEnd = $now->copy()->addDays(self::BILLS_HORIZON_DAYS)->toDateString();

        $activeAccounts = FinancialAccount::query()
            ->with(['currency', 'ledgerAccount'])
            ->where('user_id', $user->id)
            ->where('is_archived', false)
            ->orderBy('name')
            ->get();

        $archivedCounts = $this->archivedCountsByCurrencyQuery((int) $user->id);
        $activeCounts = $this->activeCountsByCurrency($activeAccounts);

        $balances = $this->balanceService->getBalancesForFinancialAccounts($activeAccounts);
        $currencyMeta = $this->currencyMetaForCodes(
            array_merge(
                $activeAccounts->pluck('currency_code')->all(),
                array_keys($archivedCounts),
            ),
        );

        $positionByCurrency = $this->buildPositionsByCurrency($activeAccounts, $balances, $currencyMeta);
        $cashFlowByCurrency = $this->buildCashFlowByCurrency(
            userId: (int) $user->id,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            currencyMeta: $currencyMeta,
        );
        $budgetByCurrency = $this->buildBudgetsByCurrency(
            user: $user,
            periodStart: $periodStart,
            currencyMeta: $currencyMeta,
        );

        $currencyCodes = array_values(array_unique(array_merge(
            array_keys($positionByCurrency),
            array_keys($cashFlowByCurrency),
            array_keys($budgetByCurrency),
            array_keys($archivedCounts),
            array_keys($activeCounts),
        )));
        sort($currencyCodes, SORT_STRING);

        $currencyMeta = $this->currencyMetaForCodes($currencyCodes);

        $currencies = [];
        foreach ($currencyCodes as $code) {
            $meta = $currencyMeta[$code] ?? ['symbol' => $code.' ', 'exponent' => 2];
            $hasActive = ($activeCounts[$code] ?? 0) > 0;
            $position = $hasActive
                ? ($positionByCurrency[$code] ?? $this->emptyPosition($code, $meta, $activeCounts[$code] ?? 0, $archivedCounts[$code] ?? 0))
                : null;

            if ($position === null && ($archivedCounts[$code] ?? 0) > 0) {
                $position = [
                    'assets' => $this->moneyView(0, $code, $meta),
                    'liabilities' => $this->moneyView(0, $code, $meta),
                    'net' => $this->moneyView(0, $code, $meta),
                    'active_account_count' => 0,
                    'archived_account_count' => $archivedCounts[$code],
                ];
            } elseif ($position !== null) {
                $position['archived_account_count'] = $archivedCounts[$code] ?? 0;
                $position['active_account_count'] = $activeCounts[$code] ?? 0;
            }

            $cashFlow = $cashFlowByCurrency[$code] ?? [
                'income' => $this->moneyView(0, $code, $meta),
                'expenses' => $this->moneyView(0, $code, $meta),
                'net' => $this->moneyView(0, $code, $meta),
            ];

            $currencies[] = [
                'currency_code' => $code,
                'position' => $position,
                'cash_flow' => $cashFlow,
                'budget' => $budgetByCurrency[$code] ?? null,
            ];
        }

        // Drop currencies with no active position, no cash flow activity, no budget, and no archived-only row.
        $currencies = array_values(array_filter(
            $currencies,
            static function (array $row): bool {
                $cash = $row['cash_flow'];
                $hasCash = ($cash['income']['amount_minor'] !== self::ZERO_MINOR)
                    || ($cash['expenses']['amount_minor'] !== self::ZERO_MINOR);
                $hasBudget = $row['budget'] !== null;
                $hasPosition = $row['position'] !== null
                    && (
                        ($row['position']['active_account_count'] ?? 0) > 0
                        || ($row['position']['archived_account_count'] ?? 0) > 0
                    );

                return $hasCash || $hasBudget || $hasPosition;
            },
        ));

        $bills = $this->buildBillsSection($user, $today, $horizonEnd, $currencyMeta);
        $savings = $this->buildSavingsSection($user, $currencyMeta);

        $hasTxnThisMonth = false;
        foreach ($cashFlowByCurrency as $cashFlow) {
            if (
                $cashFlow['income']['amount_minor'] !== self::ZERO_MINOR
                || $cashFlow['expenses']['amount_minor'] !== self::ZERO_MINOR
            ) {
                $hasTxnThisMonth = true;
                break;
            }
        }

        return new DashboardOverview(
            period: [
                'year' => (int) $now->year,
                'month' => (int) $now->month,
                'start' => $periodStart,
                'end' => $periodEnd,
                'timezone' => 'UTC',
            ],
            currencies: $currencies,
            bills: $bills,
            savings: $savings,
            empty: [
                'has_accounts' => $activeAccounts->isNotEmpty(),
                'has_transactions_this_month' => $hasTxnThisMonth,
                'has_budgets_this_month' => $budgetByCurrency !== [],
                'has_bill_items' => ($bills['overdue_count'] + $bills['upcoming_count']) > 0,
                'has_active_savings_goals' => ($savings['active_goal_count'] ?? 0) > 0,
            ],
            links: [
                'accounts' => '/accounts',
                'transactions' => '/transactions',
                'budgets' => '/budgets',
                'bills' => '/bills',
                'savings' => '/savings',
            ],
        );
    }

    /**
     * @param  Collection<int, FinancialAccount>  $accounts
     * @param  array<int, array{amount_minor: int, currency_code: string, exponent: int, symbol: string, formatted: string, is_liability: bool, has_posted_activity: bool}>  $balances
     * @param  array<string, array{symbol: string, exponent: int}>  $currencyMeta
     * @return array<string, array<string, mixed>>
     */
    private function buildPositionsByCurrency(Collection $accounts, array $balances, array $currencyMeta): array
    {
        /** @var array<string, array{assets: int, liabilities: int}> $totals */
        $totals = [];

        foreach ($accounts as $account) {
            $code = (string) $account->currency_code;
            $totals[$code] ??= ['assets' => 0, 'liabilities' => 0];
            $balance = $balances[$account->id] ?? null;
            $amount = $balance !== null ? (int) $balance['amount_minor'] : 0;
            $isLiability = $balance !== null
                ? (bool) $balance['is_liability']
                : ! $account->type->ledgerClassification()->isDebitNormal();

            if ($isLiability) {
                $totals[$code]['liabilities'] = Money::checkedAdd($totals[$code]['liabilities'], $amount);
            } else {
                $totals[$code]['assets'] = Money::checkedAdd($totals[$code]['assets'], $amount);
            }
        }

        $result = [];
        foreach ($totals as $code => $row) {
            $meta = $currencyMeta[$code] ?? ['symbol' => $code.' ', 'exponent' => 2];
            $net = Money::checkedSubtract($row['assets'], $row['liabilities']);
            $result[$code] = [
                'assets' => $this->moneyView($row['assets'], $code, $meta),
                'liabilities' => $this->moneyView($row['liabilities'], $code, $meta),
                'net' => $this->moneyView($net, $code, $meta),
                'active_account_count' => 0,
                'archived_account_count' => 0,
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, array{symbol: string, exponent: int}>  $currencyMeta
     * @return array<string, array{income: array<string, mixed>, expenses: array<string, mixed>, net: array<string, mixed>}>
     */
    private function buildCashFlowByCurrency(
        int $userId,
        string $periodStart,
        string $periodEnd,
        array &$currencyMeta,
    ): array {
        $rows = FinancialTransaction::query()
            ->active()
            ->where('user_id', $userId)
            ->whereIn('type', [
                FinancialTransactionType::Income->value,
                FinancialTransactionType::Expense->value,
            ])
            ->whereDate('entry_date', '>=', $periodStart)
            ->whereDate('entry_date', '<=', $periodEnd)
            ->selectRaw('currency_code, type, SUM(amount_minor) AS total_minor')
            ->groupBy('currency_code', 'type')
            ->orderBy('currency_code')
            ->get();

        if ($rows->isNotEmpty()) {
            $currencyMeta = $this->currencyMetaForCodes(array_merge(
                array_keys($currencyMeta),
                $rows->pluck('currency_code')->all(),
            ));
        }

        /** @var array<string, array{income: int, expenses: int}> $totals */
        $totals = [];
        foreach ($rows as $row) {
            $code = (string) $row->currency_code;
            $totals[$code] ??= ['income' => 0, 'expenses' => 0];
            $amount = (int) $row->total_minor;
            $type = $row->type instanceof FinancialTransactionType
                ? $row->type->value
                : (string) $row->getRawOriginal('type');
            if ($type === FinancialTransactionType::Income->value) {
                $totals[$code]['income'] = Money::checkedAdd($totals[$code]['income'], $amount);
            } else {
                $totals[$code]['expenses'] = Money::checkedAdd($totals[$code]['expenses'], $amount);
            }
        }

        $result = [];
        foreach ($totals as $code => $row) {
            $meta = $currencyMeta[$code] ?? ['symbol' => $code.' ', 'exponent' => 2];
            $net = Money::checkedSubtract($row['income'], $row['expenses']);
            $result[$code] = [
                'income' => $this->moneyView($row['income'], $code, $meta),
                'expenses' => $this->moneyView($row['expenses'], $code, $meta),
                'net' => $this->moneyView($net, $code, $meta),
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, array{symbol: string, exponent: int}>  $currencyMeta
     * @return array<string, array<string, mixed>>
     */
    private function buildBudgetsByCurrency(User $user, string $periodStart, array &$currencyMeta): array
    {
        $budgets = Budget::query()
            ->where('user_id', $user->id)
            ->whereDate('period_start', $periodStart)
            ->orderBy('currency_code')
            ->get();

        if ($budgets->isEmpty()) {
            return [];
        }

        $currencyMeta = $this->currencyMetaForCodes(array_merge(
            array_keys($currencyMeta),
            $budgets->pluck('currency_code')->all(),
        ));

        $result = [];
        foreach ($budgets as $budget) {
            $summary = $this->budgetService->getBudgetSpending($user, (int) $budget->id);
            $code = (string) $budget->currency_code;
            $meta = $currencyMeta[$code] ?? ['symbol' => $code.' ', 'exponent' => 2];
            $remaining = Money::checkedSubtract(
                $summary->totalAllocatedMinor,
                $summary->allocatedActualMinor,
            );

            $result[$code] = [
                'budget_id' => (int) $budget->id,
                'allocated' => $this->moneyView($summary->totalAllocatedMinor, $code, $meta),
                'spent_allocated' => $this->moneyView($summary->allocatedActualMinor, $code, $meta),
                'remaining' => $this->moneyView($remaining, $code, $meta),
                'unbudgeted_spent' => $this->moneyView($summary->unbudgetedActualMinor, $code, $meta),
                'total_spent' => $this->moneyView($summary->totalActualMinor, $code, $meta),
                'is_over_limit' => $summary->allocatedActualMinor > $summary->totalAllocatedMinor
                    || $this->anyCategoryOverLimit($summary),
            ];
        }

        return $result;
    }

    private function anyCategoryOverLimit(BudgetSpendingSummary $summary): bool
    {
        foreach ($summary->allocatedCategories as $row) {
            if ($row->isOverLimit) {
                return true;
            }
        }

        return false;
    }

    /**
     * Counts unpaid scheduled bills with SQL-level paid aggregation, then hydrates only
     * the display-cap rows (overdue first, then due date) for payment-aware presentation.
     *
     * @param  array<string, array{symbol: string, exponent: int}>  $currencyMeta
     * @return array{
     *     items: list<array<string, mixed>>,
     *     overdue_count: int,
     *     upcoming_count: int,
     *     horizon_days: int,
     *     display_cap: int
     * }
     */
    private function buildBillsSection(
        User $user,
        string $today,
        string $horizonEnd,
        array $currencyMeta,
    ): array {
        $eligible = $this->unpaidScheduledBillsQuery((int) $user->id, $horizonEnd);

        $overdueCount = (clone $eligible)
            ->whereDate('recurring_occurrences.due_date', '<', $today)
            ->count('recurring_occurrences.id');

        $upcomingCount = (clone $eligible)
            ->whereDate('recurring_occurrences.due_date', '>=', $today)
            ->whereDate('recurring_occurrences.due_date', '<=', $horizonEnd)
            ->count('recurring_occurrences.id');

        if ($overdueCount + $upcomingCount === 0) {
            return [
                'items' => [],
                'overdue_count' => 0,
                'upcoming_count' => 0,
                'horizon_days' => self::BILLS_HORIZON_DAYS,
                'display_cap' => self::BILLS_DISPLAY_CAP,
            ];
        }

        /** @var list<int> $displayIds */
        $displayIds = (clone $eligible)
            ->select('recurring_occurrences.id')
            ->orderByRaw(
                'CASE WHEN recurring_occurrences.due_date < ? THEN 0 ELSE 1 END',
                [$today],
            )
            ->orderBy('recurring_occurrences.due_date')
            ->orderBy('recurring_occurrences.id')
            ->limit(self::BILLS_DISPLAY_CAP)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($displayIds === []) {
            return [
                'items' => [],
                'overdue_count' => $overdueCount,
                'upcoming_count' => $upcomingCount,
                'horizon_days' => self::BILLS_HORIZON_DAYS,
                'display_cap' => self::BILLS_DISPLAY_CAP,
            ];
        }

        $occurrences = RecurringOccurrence::query()
            ->with([
                'obligation:id,user_id,name,currency_code,category_id,status',
                'currency:code,name,symbol,minor_unit_exponent',
            ])
            ->where('user_id', $user->id)
            ->whereIn('id', $displayIds)
            ->get()
            ->keyBy(static fn (RecurringOccurrence $occurrence): int => (int) $occurrence->id);

        /** @var list<RecurringOccurrence> $ordered */
        $ordered = [];
        foreach ($displayIds as $id) {
            $occurrence = $occurrences->get($id);
            if ($occurrence !== null) {
                $ordered[] = $occurrence;
            }
        }

        $summaries = $this->paymentService->getOccurrencePaymentSummaries($user, $ordered);

        $currencyMeta = $this->currencyMetaForCodes(array_merge(
            array_keys($currencyMeta),
            array_map(
                static fn (RecurringOccurrence $occurrence): string => (string) $occurrence->currency_code,
                $ordered,
            ),
        ));

        $items = [];
        foreach ($ordered as $occurrence) {
            $summary = $summaries[(int) $occurrence->id] ?? null;
            if ($summary === null) {
                continue;
            }

            $dueDate = $occurrence->due_date instanceof Carbon
                ? $occurrence->due_date->toDateString()
                : (string) $occurrence->due_date;

            $isOverdue = $dueDate < $today;
            $code = (string) $occurrence->currency_code;
            $meta = $currencyMeta[$code] ?? [
                'symbol' => $occurrence->currency?->symbol ?? $code.' ',
                'exponent' => $occurrence->currency?->minor_unit_exponent ?? 2,
            ];

            $items[] = [
                'occurrence_id' => (int) $occurrence->id,
                'obligation_name' => (string) ($occurrence->obligation?->name ?? 'Bill'),
                'due_date' => $dueDate,
                'currency_code' => $code,
                'expected' => $this->moneyView($summary->expectedAmountMinor, $code, $meta),
                'remaining' => $this->moneyView($summary->remainingAmountMinor, $code, $meta),
                'paid' => $this->moneyView($summary->paidAmountMinor, $code, $meta),
                'display_status' => $summary->displayStatus->value,
                'is_overdue' => $isOverdue,
            ];
        }

        return [
            'items' => $items,
            'overdue_count' => $overdueCount,
            'upcoming_count' => $upcomingCount,
            'horizon_days' => self::BILLS_HORIZON_DAYS,
            'display_cap' => self::BILLS_DISPLAY_CAP,
        ];
    }

    /**
     * Scheduled occurrences through the horizon whose active linked payments are still below expected.
     * Skipped/cancelled rows are excluded by status; fully paid rows are excluded by paid totals.
     *
     * @return Builder<RecurringOccurrence>
     */
    private function unpaidScheduledBillsQuery(int $userId, string $horizonEnd): Builder
    {
        $paidTotals = FinancialTransaction::query()
            ->select('recurring_payment_links.occurrence_id')
            ->selectRaw('COALESCE(SUM(financial_transactions.amount_minor), 0) AS paid_minor')
            ->join(
                'recurring_payment_links',
                'recurring_payment_links.financial_transaction_id',
                '=',
                'financial_transactions.id',
            )
            ->where('financial_transactions.type', FinancialTransactionType::Expense->value)
            ->whereNull('financial_transactions.reversed_transaction_id')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('financial_transactions as reversal_of_original')
                    ->whereColumn(
                        'reversal_of_original.reversed_transaction_id',
                        'financial_transactions.id',
                    );
            })
            ->groupBy('recurring_payment_links.occurrence_id');

        return RecurringOccurrence::query()
            ->leftJoinSub($paidTotals, 'dashboard_bill_paid', function ($join): void {
                $join->on('dashboard_bill_paid.occurrence_id', '=', 'recurring_occurrences.id');
            })
            ->where('recurring_occurrences.user_id', $userId)
            ->where('recurring_occurrences.status', RecurringOccurrenceStatus::Scheduled->value)
            ->whereDate('recurring_occurrences.due_date', '<=', $horizonEnd)
            ->whereRaw(
                'COALESCE(dashboard_bill_paid.paid_minor, 0) < recurring_occurrences.expected_amount_minor',
            );
    }

    /**
     * @param  array<string, array{symbol: string, exponent: int}>  $currencyMeta
     * @return array{
     *     portfolio: list<array<string, mixed>>,
     *     goals: list<array<string, mixed>>,
     *     earmark_note: string,
     *     active_goal_count: int,
     *     archived_goal_count: int
     * }
     */
    private function buildSavingsSection(User $user, array $currencyMeta): array
    {
        $portfolio = $this->savingsProgressService->getPortfolioSummary($user);
        $goalList = $this->savingsProgressService->getGoalSummaries(
            user: $user,
            status: SavingsGoalStatus::Active,
            page: 1,
            perPage: self::SAVINGS_GOALS_DISPLAY_CAP,
        );

        $currencyMeta = $this->currencyMetaForCodes(array_merge(
            array_keys($currencyMeta),
            array_map(
                static fn (SavingsGoalCurrencyPortfolioTotals $row): string => $row->currencyCode,
                $portfolio->currencies,
            ),
            $goalList->summaries->map(
                static fn (SavingsGoalProgressSummary $s): string => $s->currencyCode,
            )->all(),
        ));

        $portfolioRows = [];
        foreach ($portfolio->currencies as $row) {
            $meta = $currencyMeta[$row->currencyCode] ?? ['symbol' => $row->currencyCode.' ', 'exponent' => 2];
            $portfolioRows[] = [
                'currency_code' => $row->currencyCode,
                'active_goal_count' => $row->activeGoalCount,
                'total_target' => $this->moneyView($row->totalTargetMinor, $row->currencyCode, $meta),
                'total_allocated' => $this->moneyView($row->totalAllocatedMinor, $row->currencyCode, $meta),
                'total_remaining' => $this->moneyView($row->totalRemainingMinor, $row->currencyCode, $meta),
            ];
        }

        $goalRows = [];
        foreach ($goalList->summaries as $summary) {
            $meta = $currencyMeta[$summary->currencyCode] ?? [
                'symbol' => $summary->currencyCode.' ',
                'exponent' => 2,
            ];
            $goalRows[] = [
                'id' => $summary->goalId,
                'name' => $summary->name,
                'currency_code' => $summary->currencyCode,
                'progress_basis_points' => $summary->progressBasisPoints,
                'target_reached' => $summary->targetReached,
                'allocated' => $this->moneyView($summary->allocatedAmountMinor, $summary->currencyCode, $meta),
                'remaining' => $this->moneyView($summary->remainingAmountMinor, $summary->currencyCode, $meta),
                'target' => $this->moneyView($summary->targetAmountMinor, $summary->currencyCode, $meta),
            ];
        }

        return [
            'portfolio' => $portfolioRows,
            'goals' => $goalRows,
            'earmark_note' => self::EARMARK_NOTE,
            'active_goal_count' => $portfolio->activeGoalCount,
            'archived_goal_count' => $portfolio->archivedGoalCount,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function archivedCountsByCurrencyQuery(int $userId): array
    {
        $rows = FinancialAccount::query()
            ->where('user_id', $userId)
            ->where('is_archived', true)
            ->selectRaw('currency_code, COUNT(*) AS archived_count')
            ->groupBy('currency_code')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row->currency_code] = (int) $row->archived_count;
        }

        return $counts;
    }

    /**
     * @param  Collection<int, FinancialAccount>  $accounts
     * @return array<string, int>
     */
    private function activeCountsByCurrency(Collection $accounts): array
    {
        $counts = [];
        foreach ($accounts as $account) {
            $code = (string) $account->currency_code;
            $counts[$code] = ($counts[$code] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  list<string>|array<int|string, mixed>  $codes
     * @return array<string, array{symbol: string, exponent: int}>
     */
    private function currencyMetaForCodes(array $codes): array
    {
        $normalized = [];
        foreach ($codes as $code) {
            if (! is_string($code) || $code === '') {
                continue;
            }
            $normalized[] = strtoupper(trim($code));
        }
        $normalized = array_values(array_unique($normalized));
        if ($normalized === []) {
            return [];
        }

        $rows = Currency::query()
            ->whereIn('code', $normalized)
            ->get(['code', 'symbol', 'minor_unit_exponent']);

        $meta = [];
        foreach ($rows as $row) {
            $meta[(string) $row->code] = [
                'symbol' => (string) $row->symbol,
                'exponent' => (int) $row->minor_unit_exponent,
            ];
        }

        foreach ($normalized as $code) {
            $meta[$code] ??= ['symbol' => $code.' ', 'exponent' => 2];
        }

        return $meta;
    }

    /**
     * @param  array{symbol: string, exponent: int}  $meta
     * @return array<string, mixed>
     */
    private function emptyPosition(string $code, array $meta, int $activeCount, int $archivedCount): array
    {
        return [
            'assets' => $this->moneyView(0, $code, $meta),
            'liabilities' => $this->moneyView(0, $code, $meta),
            'net' => $this->moneyView(0, $code, $meta),
            'active_account_count' => $activeCount,
            'archived_account_count' => $archivedCount,
        ];
    }

    /**
     * @param  array{symbol: string, exponent: int}  $meta
     * @return array{
     *     amount_minor: string,
     *     currency_code: string,
     *     exponent: int,
     *     symbol: string,
     *     formatted: string
     * }
     */
    private function moneyView(int $amountMinor, string $currencyCode, array $meta): array
    {
        $money = new Money($amountMinor, $currencyCode, $meta['exponent']);

        return [
            'amount_minor' => MoneyJson::encodeMinor($amountMinor),
            'currency_code' => $currencyCode,
            'exponent' => $meta['exponent'],
            'symbol' => $meta['symbol'],
            'formatted' => $money->format($meta['symbol']),
        ];
    }
}
