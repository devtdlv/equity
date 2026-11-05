<?php

namespace App\Services;

use App\DataTransferObjects\FinancialReportQuery;
use App\Enums\FinancialTransactionType;
use App\Exceptions\LedgerIntegrityException;
use App\Models\Category;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Support\Money;
use App\Support\MoneyJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Read-only Financial Reports aggregates.
 *
 * Reuses dashboard cash-flow semantics: FinancialTransaction::active(), income/expense
 * only (transfers excluded), per-currency isolation without FX conversion, and
 * Money / MoneyJson for exact minor-unit serialization.
 */
class FinancialReportService
{
    public const TREND_MONTHS = 6;

    public const ACCOUNT_HISTORY_LIMIT = 50;

    public const MAX_CUSTOM_RANGE_DAYS = 366 * 5;

    public function __construct(
        private readonly AccountBalanceService $accountBalanceService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(FinancialReportQuery $query): array
    {
        $this->assertValidUser($query->user);

        $period = $this->resolvePeriod($query);
        $trendMonths = $this->resolveTrendMonths($period['start'], $period['end'], $query->mode);
        $trendStart = $trendMonths[0]['start'];
        $currencyMeta = $this->currencyMetaForCodes([]);

        $activityRows = FinancialTransaction::query()
            ->active()
            ->where('user_id', $query->user->id)
            ->whereIn('type', [
                FinancialTransactionType::Income->value,
                FinancialTransactionType::Expense->value,
            ])
            ->whereDate('entry_date', '>=', $trendStart)
            ->whereDate('entry_date', '<=', $period['end'])
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get([
                'id',
                'entry_date',
                'type',
                'currency_code',
                'amount_minor',
                'category_id',
            ]);

        if ($activityRows->isNotEmpty()) {
            $currencyMeta = $this->currencyMetaForCodes(
                $activityRows->pluck('currency_code')->unique()->values()->all(),
            );
        }

        $categoryNames = $this->categoryNamesForUser(
            $query->user,
            $activityRows
                ->pluck('category_id')
                ->filter(static fn ($id): bool => $id !== null)
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all(),
        );

        $currencies = $this->buildCurrencySections(
            activityRows: $activityRows,
            periodStart: $period['start'],
            periodEnd: $period['end'],
            trendMonths: $trendMonths,
            currencyMeta: $currencyMeta,
            categoryNames: $categoryNames,
            mode: $query->mode,
        );

        $accounts = $this->buildAccountOptions($query->user);
        $accountHistory = $this->buildAccountHistory(
            user: $query->user,
            accountId: $query->financialAccountId,
            periodStart: $period['start'],
            periodEnd: $period['end'],
            accounts: $accounts,
        );

        $hasPeriodActivity = $activityRows->contains(
            static function (FinancialTransaction $row) use ($period): bool {
                $date = $row->entry_date instanceof \DateTimeInterface
                    ? $row->entry_date->format('Y-m-d')
                    : (string) $row->entry_date;

                return $date >= $period['start'] && $date <= $period['end'];
            },
        );

        return [
            'filters' => [
                'mode' => $query->mode,
                'month' => $query->month,
                'start_date' => $query->mode === 'custom' ? $period['start'] : null,
                'end_date' => $query->mode === 'custom' ? $period['end'] : null,
                'financial_account_id' => $query->financialAccountId,
            ],
            'period' => $period,
            'currencies' => $currencies,
            'accounts' => $accounts,
            'account_history' => $accountHistory,
            'empty' => [
                'has_accounts' => $accounts !== [],
                'has_period_activity' => $hasPeriodActivity,
            ],
        ];
    }

    /**
     * @return array{mode: string, start: string, end: string, label: string, month: string|null}
     */
    private function resolvePeriod(FinancialReportQuery $query): array
    {
        if ($query->mode === 'custom') {
            $start = CarbonImmutable::createFromFormat('Y-m-d', $query->startDate, 'UTC');
            $end = CarbonImmutable::createFromFormat('Y-m-d', $query->endDate, 'UTC');

            if ($start === false || $end === false) {
                throw new LedgerIntegrityException('Report dates must use YYYY-MM-DD format.');
            }

            $start = $start->startOfDay();
            $end = $end->startOfDay();

            if ($start->greaterThan($end)) {
                throw new LedgerIntegrityException('Report end date must be on or after the start date.');
            }

            if ($start->diffInDays($end) > self::MAX_CUSTOM_RANGE_DAYS) {
                throw new LedgerIntegrityException(
                    'Custom report range cannot exceed five years.',
                );
            }

            return [
                'mode' => 'custom',
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'label' => $start->toDateString().' → '.$end->toDateString(),
                'month' => null,
            ];
        }

        $month = $query->month;
        if ($month === null || $month === '') {
            $month = CarbonImmutable::now('UTC')->format('Y-m');
        }

        $start = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01', 'UTC');
        if ($start === false) {
            throw new LedgerIntegrityException('Report month must use YYYY-MM format.');
        }

        $start = $start->startOfMonth();
        $end = $start->endOfMonth();

        return [
            'mode' => 'month',
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'label' => $start->format('F Y'),
            'month' => $start->format('Y-m'),
        ];
    }

    /**
     * @return list<array{key: string, label: string, start: string, end: string}>
     */
    private function resolveTrendMonths(string $periodStart, string $periodEnd, string $mode): array
    {
        if ($mode === 'custom') {
            $cursor = CarbonImmutable::createFromFormat('Y-m-d', $periodStart, 'UTC');
            $end = CarbonImmutable::createFromFormat('Y-m-d', $periodEnd, 'UTC');
            if ($cursor === false || $end === false) {
                throw new LedgerIntegrityException('Invalid report period for trend months.');
            }

            $cursor = $cursor->startOfMonth();
            $endMonth = $end->startOfMonth();
            $months = [];

            // Include every calendar month that intersects the validated custom range
            // (already capped at five years by resolvePeriod).
            while ($cursor->lessThanOrEqualTo($endMonth)) {
                $months[] = [
                    'key' => $cursor->format('Y-m'),
                    'label' => $cursor->format('M Y'),
                    'start' => $cursor->toDateString(),
                    'end' => $cursor->endOfMonth()->toDateString(),
                ];
                $cursor = $cursor->addMonth()->startOfMonth();
            }

            return $months;
        }

        $endMonth = CarbonImmutable::createFromFormat('Y-m-d', $periodStart, 'UTC');
        if ($endMonth === false) {
            throw new LedgerIntegrityException('Invalid report month for trend months.');
        }

        $endMonth = $endMonth->startOfMonth();
        $months = [];
        for ($offset = self::TREND_MONTHS - 1; $offset >= 0; $offset--) {
            $month = $endMonth->subMonthsNoOverflow($offset)->startOfMonth();
            $months[] = [
                'key' => $month->format('Y-m'),
                'label' => $month->format('M Y'),
                'start' => $month->toDateString(),
                'end' => $month->endOfMonth()->toDateString(),
            ];
        }

        return $months;
    }

    /**
     * @param  Collection<int, FinancialTransaction>  $activityRows
     * @param  list<array{key: string, label: string, start: string, end: string}>  $trendMonths
     * @param  array<string, array{symbol: string, exponent: int}>  $currencyMeta
     * @param  array<int, string>  $categoryNames
     * @return list<array<string, mixed>>
     */
    private function buildCurrencySections(
        Collection $activityRows,
        string $periodStart,
        string $periodEnd,
        array $trendMonths,
        array $currencyMeta,
        array $categoryNames,
        string $mode,
    ): array {
        /** @var array<string, array{income: int, expenses: int, categories: array<string, int>, trends: array<string, array{income: int, expenses: int}>}> $byCurrency */
        $byCurrency = [];

        foreach ($activityRows as $row) {
            $code = (string) $row->currency_code;
            $byCurrency[$code] ??= [
                'income' => 0,
                'expenses' => 0,
                'categories' => [],
                'trends' => [],
            ];

            $date = $row->entry_date instanceof \DateTimeInterface
                ? $row->entry_date->format('Y-m-d')
                : (string) $row->entry_date;
            $monthKey = substr($date, 0, 7);
            $amount = (int) $row->amount_minor;
            $type = $row->type instanceof FinancialTransactionType
                ? $row->type->value
                : (string) $row->getRawOriginal('type');

            $inPeriod = $date >= $periodStart && $date <= $periodEnd;
            // Monthly mode keeps prior months in the trend window for context.
            // Custom mode only counts in-period days so partial first/last months
            // and sum(trend) reconcile with the period summary.
            $includeInTrend = $mode === 'month' || $inPeriod;

            if ($includeInTrend) {
                $byCurrency[$code]['trends'][$monthKey] ??= ['income' => 0, 'expenses' => 0];
            }

            if ($type === FinancialTransactionType::Income->value) {
                if ($includeInTrend) {
                    $byCurrency[$code]['trends'][$monthKey]['income'] = Money::checkedAdd(
                        $byCurrency[$code]['trends'][$monthKey]['income'],
                        $amount,
                    );
                }

                if ($inPeriod) {
                    $byCurrency[$code]['income'] = Money::checkedAdd($byCurrency[$code]['income'], $amount);
                }
            } elseif ($type === FinancialTransactionType::Expense->value) {
                if ($includeInTrend) {
                    $byCurrency[$code]['trends'][$monthKey]['expenses'] = Money::checkedAdd(
                        $byCurrency[$code]['trends'][$monthKey]['expenses'],
                        $amount,
                    );
                }

                if ($inPeriod) {
                    $byCurrency[$code]['expenses'] = Money::checkedAdd($byCurrency[$code]['expenses'], $amount);
                    $categoryKey = $row->category_id !== null ? (string) (int) $row->category_id : 'uncategorized';
                    $byCurrency[$code]['categories'][$categoryKey] = Money::checkedAdd(
                        $byCurrency[$code]['categories'][$categoryKey] ?? 0,
                        $amount,
                    );
                }
            }
        }

        ksort($byCurrency);

        $sections = [];
        foreach ($byCurrency as $code => $totals) {
            $meta = $currencyMeta[$code] ?? ['symbol' => $code.' ', 'exponent' => 2];
            $net = Money::checkedSubtract($totals['income'], $totals['expenses']);

            $trendPoints = [];
            foreach ($trendMonths as $month) {
                $bucket = $totals['trends'][$month['key']] ?? ['income' => 0, 'expenses' => 0];
                $trendPoints[] = [
                    'period_key' => $month['key'],
                    'period_label' => $month['label'],
                    'income' => $this->moneyView($bucket['income'], $code, $meta),
                    'expenses' => $this->moneyView($bucket['expenses'], $code, $meta),
                    'net' => $this->moneyView(
                        Money::checkedSubtract($bucket['income'], $bucket['expenses']),
                        $code,
                        $meta,
                    ),
                ];
            }

            $categoryRows = [];
            foreach ($totals['categories'] as $categoryKey => $spent) {
                $categoryId = $categoryKey === 'uncategorized' ? null : (int) $categoryKey;
                $categoryRows[] = [
                    'category_id' => $categoryId,
                    'category_name' => $categoryId === null
                        ? 'Uncategorized'
                        : ($categoryNames[$categoryId] ?? 'Category #'.$categoryId),
                    'total' => $this->moneyView($spent, $code, $meta),
                    'share_bps' => $totals['expenses'] > 0
                        ? Money::checkedMulDivFloor($spent, 10_000, $totals['expenses'])
                        : 0,
                ];
            }

            usort(
                $categoryRows,
                static function (array $left, array $right): int {
                    $leftMinor = (int) $left['total']['amount_minor'];
                    $rightMinor = (int) $right['total']['amount_minor'];

                    return $rightMinor <=> $leftMinor;
                },
            );

            $hasPeriodTotals = $totals['income'] !== 0 || $totals['expenses'] !== 0;
            $hasTrendActivity = false;
            foreach ($trendPoints as $point) {
                if (
                    (int) $point['income']['amount_minor'] !== 0
                    || (int) $point['expenses']['amount_minor'] !== 0
                ) {
                    $hasTrendActivity = true;
                    break;
                }
            }

            if (! $hasPeriodTotals && ! $hasTrendActivity) {
                continue;
            }

            $sections[] = [
                'currency_code' => $code,
                'summary' => [
                    'income' => $this->moneyView($totals['income'], $code, $meta),
                    'expenses' => $this->moneyView($totals['expenses'], $code, $meta),
                    'net' => $this->moneyView($net, $code, $meta),
                ],
                'category_spending' => $categoryRows,
                'cash_flow_trend' => $trendPoints,
            ];
        }

        return $sections;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildAccountOptions(User $user): array
    {
        $accounts = FinancialAccount::query()
            ->with([
                'currency:code,name,symbol,minor_unit_exponent',
                'ledgerAccount',
            ])
            ->where('user_id', $user->id)
            ->orderBy('is_archived')
            ->orderBy('name')
            ->get();

        if ($accounts->isEmpty()) {
            return [];
        }

        $balances = $this->accountBalanceService->getBalancesForFinancialAccounts($accounts);

        return $accounts->map(static function (FinancialAccount $account) use ($balances): array {
            $balance = $balances[(int) $account->id] ?? null;

            return [
                'id' => (int) $account->id,
                'name' => (string) $account->name,
                'type' => (string) $account->type->value,
                'type_label' => $account->type->label(),
                'currency_code' => (string) $account->currency_code,
                'is_archived' => (bool) $account->is_archived,
                'balance' => $balance === null ? null : [
                    'amount_minor' => MoneyJson::encodeMinor((int) $balance['amount_minor']),
                    'formatted' => (string) $balance['formatted'],
                    'is_liability' => (bool) $balance['is_liability'],
                ],
            ];
        })->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $accounts
     * @return array<string, mixed>|null
     */
    private function buildAccountHistory(
        User $user,
        ?int $accountId,
        string $periodStart,
        string $periodEnd,
        array $accounts,
    ): ?array {
        if ($accountId === null) {
            return null;
        }

        $account = collect($accounts)->firstWhere('id', $accountId);
        if ($account === null) {
            throw new LedgerIntegrityException(
                'Selected financial account does not exist or is not accessible.',
            );
        }

        $query = FinancialTransaction::query()
            ->active()
            ->with([
                'currency:code,name,symbol,minor_unit_exponent',
                'category:id,name,type,is_archived',
                'financialAccount:id,name,type,currency_code,is_archived',
                'transferFinancialAccount:id,name,type,currency_code,is_archived',
            ])
            ->where('user_id', $user->id)
            ->where(function ($builder) use ($accountId): void {
                $builder->where('financial_account_id', $accountId)
                    ->orWhere('transfer_financial_account_id', $accountId);
            })
            ->whereDate('entry_date', '>=', $periodStart)
            ->whereDate('entry_date', '<=', $periodEnd)
            ->orderByDesc('entry_date')
            ->orderByDesc('id');

        $total = (clone $query)->count();
        $transactions = $query->limit(self::ACCOUNT_HISTORY_LIMIT)->get();

        return [
            'account' => $account,
            'transactions' => $transactions->map(
                fn (FinancialTransaction $transaction): array => $this->serializeHistoryTransaction($transaction),
            )->values()->all(),
            'meta' => [
                'total' => $total,
                'limit' => self::ACCOUNT_HISTORY_LIMIT,
                'capped' => $total > self::ACCOUNT_HISTORY_LIMIT,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeHistoryTransaction(FinancialTransaction $transaction): array
    {
        $currency = $transaction->currency;
        $exponent = $currency !== null ? (int) $currency->minor_unit_exponent : 2;
        $symbol = $currency !== null ? (string) $currency->symbol : $transaction->currency_code.' ';
        $money = new Money((int) $transaction->amount_minor, $transaction->currency_code, $exponent);

        return [
            'id' => (int) $transaction->id,
            'type' => $transaction->type->value,
            'type_label' => $transaction->type->label(),
            'entry_date' => $transaction->entry_date->format('Y-m-d'),
            'description' => (string) $transaction->description,
            'currency_code' => (string) $transaction->currency_code,
            'amount_minor' => MoneyJson::encodeMinor((int) $transaction->amount_minor),
            'amount_formatted' => $money->format($symbol),
            'category' => $transaction->category === null ? null : [
                'id' => (int) $transaction->category->id,
                'name' => (string) $transaction->category->name,
            ],
            'financial_account' => $transaction->financialAccount === null ? null : [
                'id' => (int) $transaction->financialAccount->id,
                'name' => (string) $transaction->financialAccount->name,
            ],
            'transfer_financial_account' => $transaction->transferFinancialAccount === null ? null : [
                'id' => (int) $transaction->transferFinancialAccount->id,
                'name' => (string) $transaction->transferFinancialAccount->name,
            ],
        ];
    }

    /**
     * @param  list<int>  $categoryIds
     * @return array<int, string>
     */
    private function categoryNamesForUser(User $user, array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        return Category::query()
            ->where('user_id', $user->id)
            ->whereIn('id', $categoryIds)
            ->pluck('name', 'id')
            ->map(static fn ($name): string => (string) $name)
            ->all();
    }

    /**
     * @param  list<string>  $codes
     * @return array<string, array{symbol: string, exponent: int}>
     */
    private function currencyMetaForCodes(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        return Currency::query()
            ->whereIn('code', $codes)
            ->get(['code', 'symbol', 'minor_unit_exponent'])
            ->mapWithKeys(static fn (Currency $currency): array => [
                (string) $currency->code => [
                    'symbol' => (string) $currency->symbol,
                    'exponent' => (int) $currency->minor_unit_exponent,
                ],
            ])
            ->all();
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

    private function assertValidUser(User $user): void
    {
        if (! $user->exists || $user->id === null) {
            throw new LedgerIntegrityException('A persisted user is required for financial reports.');
        }
    }
}
