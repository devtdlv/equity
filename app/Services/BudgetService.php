<?php

namespace App\Services;

use App\DataTransferObjects\BudgetCategorySpending;
use App\DataTransferObjects\BudgetSpendingSummary;
use App\DataTransferObjects\CreateBudgetInput;
use App\DataTransferObjects\CreatedBudgetResult;
use App\DataTransferObjects\SetBudgetAllocationInput;
use App\DataTransferObjects\SetBudgetAllocationResult;
use App\Enums\CategoryType;
use App\Enums\FinancialTransactionType;
use App\Exceptions\LedgerIntegrityException;
use App\Models\Budget;
use App\Models\BudgetAllocation;
use App\Models\Category;
use App\Models\Currency;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Support\Money;
use App\Support\MoneyInputParser;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BudgetService
{
    public const BUDGET_PERIOD_UNIQUE_INDEX = 'budgets_user_currency_period_start_unique';

    public const BUDGET_ALLOCATION_UNIQUE_INDEX = 'budget_allocations_budget_id_category_id_unique';

    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    /**
     * Creates a monthly budget for one user and currency.
     *
     * Schema 000011 has no durable idempotency key. Duplicate create requests for the same
     * (user, currency, month) are rejected via the unique index; they are not silently replayed.
     */
    public function createBudget(CreateBudgetInput $input): CreatedBudgetResult
    {
        $this->assertValidUser($input->user);
        [$periodStart, $periodEnd] = $this->resolveCalendarMonth($input->year, $input->month);
        $currencyCode = $this->normalizeCurrencyCode($input->currencyCode);

        try {
            return DB::transaction(function () use ($input, $currencyCode, $periodStart, $periodEnd): CreatedBudgetResult {
                $currency = $this->resolveActiveCurrency($currencyCode);

                $existing = Budget::query()
                    ->where('user_id', $input->user->id)
                    ->where('currency_code', $currency->code)
                    ->whereDate('period_start', $periodStart)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    throw new LedgerIntegrityException(
                        'A budget already exists for this user, currency, and month.',
                    );
                }

                $budget = Budget::query()->create([
                    'user_id' => $input->user->id,
                    'currency_code' => $currency->code,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                ]);

                $this->auditService->record(
                    actor: $input->user,
                    action: AuditService::ACTION_BUDGET_CREATED,
                    auditableType: Budget::class,
                    auditableId: (int) $budget->id,
                    metadata: [
                        'currency_code' => $budget->currency_code,
                        'period_start' => $budget->period_start->toDateString(),
                        'period_end' => $budget->period_end->toDateString(),
                    ],
                );

                return new CreatedBudgetResult(
                    budget: $budget->fresh(['currency', 'allocations']) ?? $budget,
                    wasRecentlyCreated: true,
                );
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw $this->mapBudgetUniqueViolation($exception);
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception, self::BUDGET_PERIOD_UNIQUE_INDEX)) {
                throw new LedgerIntegrityException(
                    'A budget already exists for this user, currency, and month.',
                );
            }

            throw $exception;
        }
    }

    /**
     * Creates or updates an explicit category allocation on a budget.
     *
     * An allocation with amount_minor = 0 is an explicit zero limit and is distinct from
     * a category that has no allocation row (unbudgeted).
     */
    public function setAllocation(SetBudgetAllocationInput $input): SetBudgetAllocationResult
    {
        $this->assertValidUser($input->user);

        try {
            return DB::transaction(function () use ($input): SetBudgetAllocationResult {
                $budget = Budget::query()
                    ->whereKey($input->budgetId)
                    ->lockForUpdate()
                    ->first();

                if ($budget === null || (int) $budget->user_id !== (int) $input->user->id) {
                    throw new LedgerIntegrityException('Budget is not accessible for this user.');
                }

                $this->assertBudgetEditable($budget);

                $category = Category::query()
                    ->whereKey($input->categoryId)
                    ->lockForUpdate()
                    ->first();

                if ($category === null || (int) $category->user_id !== (int) $input->user->id) {
                    throw new LedgerIntegrityException('Category is not accessible for this user.');
                }

                if ($category->type !== CategoryType::Expense) {
                    throw new LedgerIntegrityException(
                        'Only expense categories can receive budget allocations.',
                    );
                }

                $currency = $this->lockCurrency($budget->currency_code);
                $amountMinor = MoneyInputParser::parseNonNegativeToMinorUnits(
                    $input->amount,
                    (int) $currency->minor_unit_exponent,
                );

                $allocation = BudgetAllocation::query()
                    ->where('budget_id', $budget->id)
                    ->where('category_id', $category->id)
                    ->lockForUpdate()
                    ->first();

                if ($allocation === null && $category->is_archived) {
                    throw new LedgerIntegrityException(
                        'Archived categories cannot receive new budget allocations.',
                    );
                }

                $previousAmountMinor = $allocation?->amount_minor;

                if ($allocation === null) {
                    $allocation = BudgetAllocation::query()->create([
                        'budget_id' => $budget->id,
                        'user_id' => $input->user->id,
                        'category_id' => $category->id,
                        'category_type' => CategoryType::Expense,
                        'amount_minor' => $amountMinor,
                    ]);
                    $wasRecentlyCreated = true;
                } else {
                    $allocation->amount_minor = $amountMinor;
                    $allocation->save();
                    $wasRecentlyCreated = false;
                }

                $this->auditService->record(
                    actor: $input->user,
                    action: AuditService::ACTION_BUDGET_ALLOCATION_SET,
                    auditableType: BudgetAllocation::class,
                    auditableId: (int) $allocation->id,
                    metadata: [
                        'budget_id' => (int) $budget->id,
                        'category_id' => (int) $category->id,
                        'currency_code' => $budget->currency_code,
                        'previous_amount_minor' => $previousAmountMinor,
                        'new_amount_minor' => $amountMinor,
                        'was_recently_created' => $wasRecentlyCreated,
                    ],
                );

                return new SetBudgetAllocationResult(
                    allocation: $allocation->fresh(['category', 'budget']) ?? $allocation,
                    previousAmountMinor: $previousAmountMinor,
                    wasRecentlyCreated: $wasRecentlyCreated,
                );
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw $this->mapAllocationUniqueViolation($exception);
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception, self::BUDGET_ALLOCATION_UNIQUE_INDEX)) {
                throw new LedgerIntegrityException(
                    'A budget allocation already exists for this category.',
                );
            }

            throw $exception;
        }
    }

    /**
     * Computes allocated vs actual spending for a user-owned budget using active expense transactions.
     */
    public function getBudgetSpending(User $user, int $budgetId): BudgetSpendingSummary
    {
        $this->assertValidUser($user);

        $budget = Budget::query()
            ->with(['allocations.category'])
            ->whereKey($budgetId)
            ->where('user_id', $user->id)
            ->first();

        if ($budget === null) {
            throw new LedgerIntegrityException('Budget is not accessible for this user.');
        }

        $periodStart = $budget->period_start->toDateString();
        $periodEnd = $budget->period_end->toDateString();
        $allocatedCategoryIds = $budget->allocations
            ->pluck('category_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $actualByCategory = $this->sumActiveExpenseActualsByCategory(
            userId: (int) $user->id,
            currencyCode: $budget->currency_code,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
        );

        $allocatedCategories = [];
        $totalAllocatedMinor = 0;
        $allocatedActualMinor = 0;

        foreach ($budget->allocations->sortBy('category_id') as $allocation) {
            $categoryId = (int) $allocation->category_id;
            $allocatedMinor = (int) $allocation->amount_minor;
            $actualMinor = $actualByCategory[$categoryId] ?? 0;
            $remainingMinor = Money::checkedSubtract($allocatedMinor, $actualMinor);

            $category = $allocation->category;

            $allocatedCategories[] = new BudgetCategorySpending(
                categoryId: $categoryId,
                categoryName: $category?->name ?? 'Category',
                categoryIsArchived: (bool) ($category?->is_archived ?? false),
                allocatedMinor: $allocatedMinor,
                actualMinor: $actualMinor,
                remainingMinor: $remainingMinor,
                isOverLimit: $actualMinor > $allocatedMinor,
            );

            $totalAllocatedMinor = Money::checkedAdd($totalAllocatedMinor, $allocatedMinor);
            $allocatedActualMinor = Money::checkedAdd($allocatedActualMinor, $actualMinor);
        }

        $unbudgetedActualMinor = 0;
        foreach ($actualByCategory as $categoryId => $actualMinor) {
            if (in_array((int) $categoryId, $allocatedCategoryIds, true)) {
                continue;
            }

            $unbudgetedActualMinor = Money::checkedAdd($unbudgetedActualMinor, $actualMinor);
        }

        return new BudgetSpendingSummary(
            budget: $budget,
            allocatedCategories: array_values($allocatedCategories),
            totalAllocatedMinor: $totalAllocatedMinor,
            allocatedActualMinor: $allocatedActualMinor,
            unbudgetedActualMinor: $unbudgetedActualMinor,
            totalActualMinor: Money::checkedAdd($allocatedActualMinor, $unbudgetedActualMinor),
        );
    }

    /**
     * @return array{0: string, 1: string} period_start, period_end as Y-m-d
     */
    private function resolveCalendarMonth(int $year, int $month): array
    {
        if ($year < 1 || $year > 9999 || $month < 1 || $month > 12) {
            throw new LedgerIntegrityException('Budget year and month must form a valid calendar month.');
        }

        $start = Carbon::create($year, $month, 1, 0, 0, 0, 'UTC');
        if ($start === null) {
            throw new LedgerIntegrityException('Budget year and month must form a valid calendar month.');
        }

        $periodStart = $start->toDateString();
        $periodEnd = $start->copy()->endOfMonth()->toDateString();

        if (! Budget::isValidCalendarMonth($periodStart, $periodEnd)) {
            throw new LedgerIntegrityException(
                'Budget period_start must be the first day of a month and period_end must be the last day of the same month.',
            );
        }

        return [$periodStart, $periodEnd];
    }

    private function assertBudgetEditable(Budget $budget): void
    {
        $currentMonthStart = now('UTC')->startOfMonth()->toDateString();

        if ($budget->period_start->toDateString() < $currentMonthStart) {
            throw new LedgerIntegrityException('Past-month budgets are read-only.');
        }
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
                'Currency [%s] could not be resolved for this budget.',
                $currencyCode,
            ));
        }

        return $currency;
    }

    private function normalizeCurrencyCode(string $currencyCode): string
    {
        $normalized = strtoupper(trim($currencyCode));

        if (strlen($normalized) !== 3 || ! ctype_alpha($normalized)) {
            throw new LedgerIntegrityException('Currency code must be a 3-letter ISO 4217 code.');
        }

        return $normalized;
    }

    private function assertValidUser(User $user): void
    {
        if ($user->id === null) {
            throw new LedgerIntegrityException('A persisted user is required for budget operations.');
        }
    }

    /**
     * @return array<int, int> category_id => actual_minor
     */
    private function sumActiveExpenseActualsByCategory(
        int $userId,
        string $currencyCode,
        string $periodStart,
        string $periodEnd,
    ): array {
        $amounts = FinancialTransaction::query()
            ->active()
            ->where('user_id', $userId)
            ->where('currency_code', $currencyCode)
            ->where('type', FinancialTransactionType::Expense->value)
            ->whereDate('entry_date', '>=', $periodStart)
            ->whereDate('entry_date', '<=', $periodEnd)
            ->whereNotNull('category_id')
            ->orderBy('id')
            ->get(['id', 'category_id', 'amount_minor']);

        $totals = [];

        foreach ($amounts as $transaction) {
            $categoryId = (int) $transaction->category_id;
            $current = $totals[$categoryId] ?? 0;
            $totals[$categoryId] = Money::checkedAdd($current, (int) $transaction->amount_minor);
        }

        return $totals;
    }

    private function mapBudgetUniqueViolation(UniqueConstraintViolationException $exception): LedgerIntegrityException
    {
        if ($this->isUniqueConstraintViolation($exception, self::BUDGET_PERIOD_UNIQUE_INDEX)) {
            return new LedgerIntegrityException(
                'A budget already exists for this user, currency, and month.',
            );
        }

        return new LedgerIntegrityException('Budget could not be created due to a uniqueness conflict.');
    }

    private function mapAllocationUniqueViolation(UniqueConstraintViolationException $exception): LedgerIntegrityException
    {
        if ($this->isUniqueConstraintViolation($exception, self::BUDGET_ALLOCATION_UNIQUE_INDEX)) {
            return new LedgerIntegrityException(
                'A budget allocation already exists for this category.',
            );
        }

        return new LedgerIntegrityException('Budget allocation could not be saved due to a uniqueness conflict.');
    }

    private function isUniqueConstraintViolation(QueryException $exception, string $indexName): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, strtolower($indexName))
            || str_contains($message, 'unique')
            || (string) $exception->getCode() === '23000';
    }
}
