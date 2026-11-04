<?php

use App\DataTransferObjects\AllocateSavingsGoalInput;
use App\DataTransferObjects\CreateBudgetInput;
use App\DataTransferObjects\CreateFinancialTransactionInput;
use App\DataTransferObjects\CreateRecurringObligationInput;
use App\DataTransferObjects\CreateSavingsGoalInput;
use App\DataTransferObjects\LinkRecurringPaymentInput;
use App\DataTransferObjects\ReverseFinancialTransactionInput;
use App\DataTransferObjects\SetBudgetAllocationInput;
use App\Enums\CategoryType;
use App\Enums\FinancialTransactionType;
use App\Enums\RecurringFrequency;
use App\Enums\RecurringOccurrenceStatus;
use App\Models\RecurringOccurrence;
use App\Models\User;
use App\Services\BudgetService;
use App\Services\CategoryService;
use App\Services\DashboardOverviewService;
use App\Services\FinancialAccountService;
use App\Services\FinancialTransactionService;
use App\Services\RecurringObligationService;
use App\Services\RecurringPaymentService;
use App\Services\SavingsGoalAllocationService;
use App\Services\SavingsGoalService;
use App\Support\Money;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CurrencySeeder::class);
    Carbon::setTestNow(Carbon::parse('2026-11-15 12:00:00', 'UTC'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array{
 *     user: User,
 *     otherUser: User,
 *     overview: DashboardOverviewService,
 *     accounts: FinancialAccountService,
 *     transactions: FinancialTransactionService,
 *     categories: CategoryService,
 *     budgets: BudgetService,
 *     obligations: RecurringObligationService,
 *     payments: RecurringPaymentService,
 *     savings: SavingsGoalService,
 *     allocations: SavingsGoalAllocationService
 * }
 */
function dashboardFixtures(): array
{
    return [
        'user' => User::factory()->create(),
        'otherUser' => User::factory()->create(),
        'overview' => app(DashboardOverviewService::class),
        'accounts' => app(FinancialAccountService::class),
        'transactions' => app(FinancialTransactionService::class),
        'categories' => app(CategoryService::class),
        'budgets' => app(BudgetService::class),
        'obligations' => app(RecurringObligationService::class),
        'payments' => app(RecurringPaymentService::class),
        'savings' => app(SavingsGoalService::class),
        'allocations' => app(SavingsGoalAllocationService::class),
    ];
}

/**
 * @param  array<string, mixed>  $fixtures
 */
function makeBillOccurrence(
    array $fixtures,
    User $user,
    int $categoryId,
    int $accountId,
    string $name,
    string $startDate,
    string $amount = '100.00',
    string $currencyCode = 'PHP',
): RecurringOccurrence {
    $created = $fixtures['obligations']->create(new CreateRecurringObligationInput(
        user: $user,
        idempotencyKey: (string) Str::uuid(),
        name: $name,
        amount: $amount,
        currencyCode: $currencyCode,
        categoryId: $categoryId,
        startDate: $startDate,
        frequency: RecurringFrequency::Monthly,
        anchorDay: (int) Carbon::parse($startDate, 'UTC')->day,
        financialAccountId: $accountId,
    ));

    return RecurringOccurrence::query()
        ->where('obligation_id', $created->obligation->id)
        ->whereDate('due_date', $startDate)
        ->firstOrFail();
}

test('empty workspace returns empty flags and no currency sections', function (): void {
    $fixtures = dashboardFixtures();

    $overview = $fixtures['overview']->build($fixtures['user']);
    $props = $overview->toInertiaProps();

    expect($props['currencies'])->toBe([])
        ->and($props['empty']['has_accounts'])->toBeFalse()
        ->and($props['empty']['has_transactions_this_month'])->toBeFalse()
        ->and($props['empty']['has_budgets_this_month'])->toBeFalse()
        ->and($props['empty']['has_bill_items'])->toBeFalse()
        ->and($props['empty']['has_active_savings_goals'])->toBeFalse()
        ->and($props['period']['timezone'])->toBe('UTC')
        ->and($props['period']['start'])->toBe('2026-11-01')
        ->and($props['period']['end'])->toBe('2026-11-30');
});

test('active account position separates assets and credit-card liabilities and excludes archived', function (): void {
    $fixtures = dashboardFixtures();
    $user = $fixtures['user'];

    $checking = $fixtures['accounts']->createAccount($user, [
        'name' => 'Checking',
        'type' => 'checking',
        'currency_code' => 'PHP',
        'starting_balance' => '1000.00',
    ]);
    $card = $fixtures['accounts']->createAccount($user, [
        'name' => 'Card',
        'type' => 'credit_card',
        'currency_code' => 'PHP',
        'starting_balance' => '250.00',
    ]);
    $archived = $fixtures['accounts']->createAccount($user, [
        'name' => 'Old Cash',
        'type' => 'cash',
        'currency_code' => 'PHP',
        'starting_balance' => '50.00',
    ]);
    $fixtures['accounts']->archiveAccount($user, $archived);

    $usd = $fixtures['accounts']->createAccount($user, [
        'name' => 'USD Checking',
        'type' => 'checking',
        'currency_code' => 'USD',
        'starting_balance' => '10.00',
    ]);

    $props = $fixtures['overview']->build($user)->toInertiaProps();
    $byCode = collect($props['currencies'])->keyBy('currency_code');

    expect($byCode)->toHaveKeys(['PHP', 'USD']);

    $php = $byCode['PHP'];
    expect($php['position']['assets']['amount_minor'])->toBe('100000')
        ->and($php['position']['liabilities']['amount_minor'])->toBe('25000')
        ->and($php['position']['net']['amount_minor'])->toBe('75000')
        ->and($php['position']['active_account_count'])->toBe(2)
        ->and($php['position']['archived_account_count'])->toBe(1)
        ->and($byCode['USD']['position']['assets']['amount_minor'])->toBe('1000')
        ->and($byCode['USD']['position']['liabilities']['amount_minor'])->toBe('0');

    expect($checking->id)->toBeInt()->and($card->id)->toBeInt()->and($usd->id)->toBeInt();
});

test('cash flow uses active income and expenses in the UTC month and ignores transfers and reversals', function (): void {
    $fixtures = dashboardFixtures();
    $user = $fixtures['user'];

    $expenseCat = $fixtures['categories']->createCategory($user, [
        'name' => 'Food',
        'type' => CategoryType::Expense,
    ]);
    $incomeCat = $fixtures['categories']->createCategory($user, [
        'name' => 'Salary',
        'type' => CategoryType::Income,
    ]);
    $checking = $fixtures['accounts']->createAccount($user, [
        'name' => 'Checking',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);
    $savings = $fixtures['accounts']->createAccount($user, [
        'name' => 'Savings',
        'type' => 'savings',
        'currency_code' => 'PHP',
    ]);

    $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Income,
        financialAccountId: $checking->id,
        amount: '500.00',
        entryDate: '2026-11-10',
        description: 'Pay',
        idempotencyKey: (string) Str::uuid(),
        categoryId: $incomeCat->id,
    ));
    $expense = $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $checking->id,
        amount: '40.00',
        entryDate: '2026-11-12',
        description: 'Groceries',
        idempotencyKey: (string) Str::uuid(),
        categoryId: $expenseCat->id,
    ));
    $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Transfer,
        financialAccountId: $checking->id,
        amount: '25.00',
        entryDate: '2026-11-13',
        description: 'Transfer',
        idempotencyKey: (string) Str::uuid(),
        transferFinancialAccountId: $savings->id,
    ));
    // Outside month — last day of prior month
    $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $checking->id,
        amount: '99.00',
        entryDate: '2026-10-31',
        description: 'Prior month',
        idempotencyKey: (string) Str::uuid(),
        categoryId: $expenseCat->id,
    ));
    // Outside month — first day of next month
    $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $checking->id,
        amount: '88.00',
        entryDate: '2026-12-01',
        description: 'Next month',
        idempotencyKey: (string) Str::uuid(),
        categoryId: $expenseCat->id,
    ));
    // Last day of current UTC month included
    $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $checking->id,
        amount: '5.00',
        entryDate: '2026-11-30',
        description: 'Month end',
        idempotencyKey: (string) Str::uuid(),
        categoryId: $expenseCat->id,
    ));

    $toReverse = $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $checking->id,
        amount: '15.00',
        entryDate: '2026-11-14',
        description: 'Will reverse',
        idempotencyKey: (string) Str::uuid(),
        categoryId: $expenseCat->id,
    ));
    $fixtures['transactions']->reverseTransaction(new ReverseFinancialTransactionInput(
        user: $user,
        originalTransactionId: $toReverse->transaction->id,
        idempotencyKey: (string) Str::uuid(),
        reason: 'mistake',
    ));

    $props = $fixtures['overview']->build($user)->toInertiaProps();
    $php = collect($props['currencies'])->firstWhere('currency_code', 'PHP');

    expect($php['cash_flow']['income']['amount_minor'])->toBe('50000')
        ->and($php['cash_flow']['expenses']['amount_minor'])->toBe('4500')
        ->and($php['cash_flow']['net']['amount_minor'])->toBe('45500')
        ->and($expense->transaction->id)->toBeInt();
});

test('budget section includes remaining and unbudgeted spending', function (): void {
    $fixtures = dashboardFixtures();
    $user = $fixtures['user'];

    $groceries = $fixtures['categories']->createCategory($user, [
        'name' => 'Groceries',
        'type' => CategoryType::Expense,
    ]);
    $dining = $fixtures['categories']->createCategory($user, [
        'name' => 'Dining',
        'type' => CategoryType::Expense,
    ]);
    $checking = $fixtures['accounts']->createAccount($user, [
        'name' => 'Checking',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);

    $budget = $fixtures['budgets']->createBudget(new CreateBudgetInput(
        user: $user,
        currencyCode: 'PHP',
        year: 2026,
        month: 11,
    ))->budget;

    $fixtures['budgets']->setAllocation(new SetBudgetAllocationInput(
        user: $user,
        budgetId: $budget->id,
        categoryId: $groceries->id,
        amount: '100.00',
    ));

    $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $checking->id,
        amount: '30.00',
        entryDate: '2026-11-05',
        description: 'Groceries',
        idempotencyKey: (string) Str::uuid(),
        categoryId: $groceries->id,
    ));
    $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $checking->id,
        amount: '12.00',
        entryDate: '2026-11-06',
        description: 'Dining unbudgeted',
        idempotencyKey: (string) Str::uuid(),
        categoryId: $dining->id,
    ));

    $props = $fixtures['overview']->build($user)->toInertiaProps();
    $php = collect($props['currencies'])->firstWhere('currency_code', 'PHP');

    expect($php['budget'])->not->toBeNull()
        ->and($php['budget']['allocated']['amount_minor'])->toBe('10000')
        ->and($php['budget']['spent_allocated']['amount_minor'])->toBe('3000')
        ->and($php['budget']['remaining']['amount_minor'])->toBe('7000')
        ->and($php['budget']['unbudgeted_spent']['amount_minor'])->toBe('1200')
        ->and($php['budget']['total_spent']['amount_minor'])->toBe('4200');
});

test('bills prioritize overdue, preserve partial remaining, exclude paid skipped cancelled, and enforce exact display cap', function (): void {
    $fixtures = dashboardFixtures();
    $user = $fixtures['user'];
    $expense = $fixtures['categories']->createCategory($user, [
        'name' => 'Bills',
        'type' => CategoryType::Expense,
    ]);
    $checking = $fixtures['accounts']->createAccount($user, [
        'name' => 'Checking',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);

    // Older overdue history (prior month) plus enough current-month overdue rows to exceed the cap.
    // Nov-only starts keep the next monthly slot outside the 14-day horizon.
    $oldOverdue = makeBillOccurrence($fixtures, $user, $expense->id, $checking->id, 'Old Overdue Oct', '2026-10-05');
    // Oct 5 monthly also generates Nov 5 for the same obligation.
    $overdueDays = [1, 2, 3, 4, 6, 7, 8, 9];
    foreach ($overdueDays as $day) {
        makeBillOccurrence(
            $fixtures,
            $user,
            $expense->id,
            $checking->id,
            sprintf('Overdue %02d', $day),
            sprintf('2026-11-%02d', $day),
        );
    }

    // Due Nov 3 so it stays inside the overdue-first display window of 8.
    $partialOverdue = makeBillOccurrence(
        $fixtures,
        $user,
        $expense->id,
        $checking->id,
        'Partial Overdue Utilities',
        '2026-11-03',
        '200.00',
    );

    $paid = makeBillOccurrence($fixtures, $user, $expense->id, $checking->id, 'Paid Phone', '2026-11-16');
    $skipped = makeBillOccurrence($fixtures, $user, $expense->id, $checking->id, 'Skipped Mag', '2026-11-17');
    $toCancel = makeBillOccurrence($fixtures, $user, $expense->id, $checking->id, 'Cancelled Gym', '2026-11-22');
    $far = makeBillOccurrence($fixtures, $user, $expense->id, $checking->id, 'Far Future', '2026-12-20');

    $partialOverdueTxn = $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $checking->id,
        amount: '50.00',
        entryDate: '2026-11-15',
        description: 'Partial overdue util',
        idempotencyKey: (string) Str::uuid(),
        categoryId: $expense->id,
    ));
    $fixtures['payments']->linkPayment(new LinkRecurringPaymentInput(
        user: $user,
        occurrenceId: (int) $partialOverdue->id,
        financialTransactionId: (int) $partialOverdueTxn->transaction->id,
    ));

    $paidTxn = $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $checking->id,
        amount: '100.00',
        entryDate: '2026-11-15',
        description: 'Paid phone',
        idempotencyKey: (string) Str::uuid(),
        categoryId: $expense->id,
    ));
    $fixtures['payments']->linkPayment(new LinkRecurringPaymentInput(
        user: $user,
        occurrenceId: (int) $paid->id,
        financialTransactionId: (int) $paidTxn->transaction->id,
    ));

    $fixtures['obligations']->skipOccurrence($user, (int) $skipped->id);
    $fixtures['obligations']->cancel($user, (int) $toCancel->obligation_id);

    for ($i = 0; $i < 3; $i++) {
        makeBillOccurrence(
            $fixtures,
            $user,
            $expense->id,
            $checking->id,
            "Extra {$i}",
            sprintf('2026-11-%02d', 18 + $i),
            '10.00',
        );
    }

    $props = $fixtures['overview']->build($user)->toInertiaProps();
    $bills = $props['bills'];

    // Overdue: Old Oct 5 + generated Nov 5 + 8 Nov overdue days + partial Nov 3 = 11
    // Upcoming: 3 extras (paid/skipped/cancelled/far excluded)
    expect($bills['display_cap'])->toBe(8)
        ->and(count($bills['items']))->toBe(8)
        ->and($bills['overdue_count'])->toBe(11)
        ->and($bills['upcoming_count'])->toBe(3)
        ->and($bills['overdue_count'] + $bills['upcoming_count'])->toBeGreaterThan(8);

    $names = collect($bills['items'])->pluck('obligation_name')->all();
    expect($names[0])->toBe('Old Overdue Oct')
        ->and($bills['items'][0]['is_overdue'])->toBeTrue()
        ->and($names)->toContain('Partial Overdue Utilities')
        ->and($names)->not->toContain('Paid Phone')
        ->and($names)->not->toContain('Skipped Mag')
        ->and($names)->not->toContain('Cancelled Gym')
        ->and($names)->not->toContain('Far Future')
        // Display is overdue-first; with 11 overdue, no upcoming extras appear in the capped list.
        ->and($names)->not->toContain('Extra 0');

    $partialOverdueRow = collect($bills['items'])->firstWhere('obligation_name', 'Partial Overdue Utilities');
    expect($partialOverdueRow)->not->toBeNull()
        ->and($partialOverdueRow['remaining']['amount_minor'])->toBe('15000')
        ->and($partialOverdueRow['display_status'])->toBe('partially_paid')
        ->and($partialOverdueRow['is_overdue'])->toBeTrue();

    expect($oldOverdue->status)->toBe(RecurringOccurrenceStatus::Scheduled)
        ->and($far->id)->toBeInt()
        ->and(RecurringOccurrence::query()->whereKey($toCancel->id)->firstOrFail()->status)
        ->toBe(RecurringOccurrenceStatus::Cancelled);
});

test('partial upcoming remaining is visible when overdue volume leaves room under the display cap', function (): void {
    $fixtures = dashboardFixtures();
    $user = $fixtures['user'];
    $expense = $fixtures['categories']->createCategory($user, [
        'name' => 'Bills',
        'type' => CategoryType::Expense,
    ]);
    $checking = $fixtures['accounts']->createAccount($user, [
        'name' => 'Checking',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);

    makeBillOccurrence($fixtures, $user, $expense->id, $checking->id, 'Only Overdue', '2026-11-01');
    $partial = makeBillOccurrence(
        $fixtures,
        $user,
        $expense->id,
        $checking->id,
        'Utilities Partial',
        '2026-11-18',
        '200.00',
    );

    $txn = $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $checking->id,
        amount: '50.00',
        entryDate: '2026-11-15',
        description: 'Partial util',
        idempotencyKey: (string) Str::uuid(),
        categoryId: $expense->id,
    ));
    $fixtures['payments']->linkPayment(new LinkRecurringPaymentInput(
        user: $user,
        occurrenceId: (int) $partial->id,
        financialTransactionId: (int) $txn->transaction->id,
    ));

    $props = $fixtures['overview']->build($user)->toInertiaProps();
    $row = collect($props['bills']['items'])->firstWhere('obligation_name', 'Utilities Partial');

    expect($row)->not->toBeNull()
        ->and($row['remaining']['amount_minor'])->toBe('15000')
        ->and($row['display_status'])->toBe('partially_paid')
        ->and($row['is_overdue'])->toBeFalse()
        ->and($props['bills']['overdue_count'])->toBe(1)
        ->and($props['bills']['upcoming_count'])->toBe(1)
        ->and(count($props['bills']['items']))->toBe(2);
});

test('savings portfolio and goals serialize earmark progress without touching accounts', function (): void {
    $fixtures = dashboardFixtures();
    $user = $fixtures['user'];

    $checking = $fixtures['accounts']->createAccount($user, [
        'name' => 'Checking',
        'type' => 'checking',
        'currency_code' => 'PHP',
        'starting_balance' => '100.00',
    ]);

    $goal = $fixtures['savings']->createGoal(new CreateSavingsGoalInput(
        user: $user,
        idempotencyKey: (string) Str::uuid(),
        name: 'Emergency',
        currencyCode: 'PHP',
        targetAmountMinor: 100_000,
    ))->goal;

    $fixtures['allocations']->contribute(new AllocateSavingsGoalInput(
        user: $user,
        goalId: $goal->id,
        idempotencyKey: (string) Str::uuid(),
        amountMinor: 25_000,
    ));

    $props = $fixtures['overview']->build($user)->toInertiaProps();

    expect($props['savings']['earmark_note'])->toContain('earmarks')
        ->and($props['savings']['active_goal_count'])->toBe(1)
        ->and($props['savings']['goals'][0]['allocated']['amount_minor'])->toBe('25000')
        ->and($props['savings']['portfolio'][0]['total_allocated']['amount_minor'])->toBe('25000');

    $php = collect($props['currencies'])->firstWhere('currency_code', 'PHP');
    expect($php['position']['assets']['amount_minor'])->toBe('10000')
        ->and($checking->id)->toBeInt();
});

test('overview isolates cash flow budgets bills and savings to the authenticated user', function (): void {
    $fixtures = dashboardFixtures();
    $user = $fixtures['user'];
    $other = $fixtures['otherUser'];

    $otherExpense = $fixtures['categories']->createCategory($other, [
        'name' => 'Other Bills',
        'type' => CategoryType::Expense,
    ]);
    $otherIncome = $fixtures['categories']->createCategory($other, [
        'name' => 'Other Income',
        'type' => CategoryType::Income,
    ]);
    $otherAccount = $fixtures['accounts']->createAccount($other, [
        'name' => 'Other',
        'type' => 'checking',
        'currency_code' => 'USD',
        'starting_balance' => '999.00',
    ]);
    $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $other,
        type: FinancialTransactionType::Income,
        financialAccountId: $otherAccount->id,
        amount: '50.00',
        entryDate: '2026-11-10',
        description: 'Other pay',
        idempotencyKey: (string) Str::uuid(),
        categoryId: $otherIncome->id,
    ));
    $fixtures['budgets']->createBudget(new CreateBudgetInput(
        user: $other,
        currencyCode: 'USD',
        year: 2026,
        month: 11,
    ));
    makeBillOccurrence(
        $fixtures,
        $other,
        $otherExpense->id,
        $otherAccount->id,
        'Other Rent',
        '2026-11-01',
        '100.00',
        'USD',
    );
    $fixtures['savings']->createGoal(new CreateSavingsGoalInput(
        user: $other,
        idempotencyKey: (string) Str::uuid(),
        name: 'Other Goal',
        currencyCode: 'USD',
        targetAmountMinor: 50_000,
    ));

    $mineExpense = $fixtures['categories']->createCategory($user, [
        'name' => 'Mine Bills',
        'type' => CategoryType::Expense,
    ]);
    $mineAccount = $fixtures['accounts']->createAccount($user, [
        'name' => 'Mine',
        'type' => 'checking',
        'currency_code' => 'PHP',
        'starting_balance' => '1.00',
    ]);
    $fixtures['budgets']->createBudget(new CreateBudgetInput(
        user: $user,
        currencyCode: 'PHP',
        year: 2026,
        month: 11,
    ));
    makeBillOccurrence($fixtures, $user, $mineExpense->id, $mineAccount->id, 'Mine Rent', '2026-11-01');
    $fixtures['savings']->createGoal(new CreateSavingsGoalInput(
        user: $user,
        idempotencyKey: (string) Str::uuid(),
        name: 'Mine Goal',
        currencyCode: 'PHP',
        targetAmountMinor: 10_000,
    ));

    $props = $fixtures['overview']->build($user)->toInertiaProps();
    $codes = collect($props['currencies'])->pluck('currency_code')->all();

    expect($codes)->toBe(['PHP'])
        ->and($codes)->not->toContain('USD')
        ->and($props['bills']['overdue_count'])->toBe(1)
        ->and(collect($props['bills']['items'])->pluck('obligation_name')->all())->toBe(['Mine Rent'])
        ->and($props['savings']['active_goal_count'])->toBe(1)
        ->and($props['savings']['goals'][0]['name'])->toBe('Mine Goal')
        ->and($props['currencies'][0]['budget'])->not->toBeNull()
        ->and($props['currencies'][0]['budget']['allocated']['currency_code'])->toBe('PHP');
});

test('large minor amounts are serialized as digit strings', function (): void {
    $fixtures = dashboardFixtures();
    $user = $fixtures['user'];

    // 9_007_199_254_740_993 exceeds JS MAX_SAFE_INTEGER; stay within Money::MAX.
    $large = 9_007_199_254_740_993;
    expect($large)->toBeLessThanOrEqual(Money::MAX_MINOR_AMOUNT);

    $goal = $fixtures['savings']->createGoal(new CreateSavingsGoalInput(
        user: $user,
        idempotencyKey: (string) Str::uuid(),
        name: 'Big',
        currencyCode: 'PHP',
        targetAmountMinor: $large,
    ))->goal;

    $fixtures['allocations']->contribute(new AllocateSavingsGoalInput(
        user: $user,
        goalId: $goal->id,
        idempotencyKey: (string) Str::uuid(),
        amountMinor: $large,
    ));

    $props = $fixtures['overview']->build($user)->toInertiaProps();
    $allocated = $props['savings']['goals'][0]['allocated']['amount_minor'];

    expect($allocated)->toBe((string) $large)
        ->and(is_string($allocated))->toBeTrue();
});
