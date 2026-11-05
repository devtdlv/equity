<?php

use App\DataTransferObjects\CreateFinancialTransactionInput;
use App\DataTransferObjects\FinancialReportQuery;
use App\DataTransferObjects\ReverseFinancialTransactionInput;
use App\Enums\CategoryType;
use App\Enums\FinancialTransactionType;
use App\Models\User;
use App\Services\CategoryService;
use App\Services\DashboardOverviewService;
use App\Services\FinancialAccountService;
use App\Services\FinancialReportService;
use App\Services\FinancialTransactionService;
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
 *     reports: FinancialReportService,
 *     accounts: FinancialAccountService,
 *     transactions: FinancialTransactionService,
 *     categories: CategoryService,
 *     checking: \App\Models\FinancialAccount,
 *     usd: \App\Models\FinancialAccount,
 *     rent: \App\Models\Category,
 *     salary: \App\Models\Category,
 *     groceries: \App\Models\Category
 * }
 */
function reportFixtures(): array
{
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $accounts = app(FinancialAccountService::class);
    $categories = app(CategoryService::class);
    $transactions = app(FinancialTransactionService::class);

    $checking = $accounts->createAccount($user, [
        'name' => 'Checking PHP',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);
    $savings = $accounts->createAccount($user, [
        'name' => 'Savings PHP',
        'type' => 'savings',
        'currency_code' => 'PHP',
    ]);
    $usd = $accounts->createAccount($user, [
        'name' => 'Checking USD',
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);
    $rent = $categories->createCategory($user, ['name' => 'Rent', 'type' => CategoryType::Expense]);
    $groceries = $categories->createCategory($user, ['name' => 'Groceries', 'type' => CategoryType::Expense]);
    $salary = $categories->createCategory($user, ['name' => 'Salary', 'type' => CategoryType::Income]);

    return [
        'user' => $user,
        'otherUser' => $otherUser,
        'reports' => app(FinancialReportService::class),
        'accounts' => $accounts,
        'transactions' => $transactions,
        'categories' => $categories,
        'checking' => $checking,
        'savings' => $savings,
        'usd' => $usd,
        'rent' => $rent,
        'salary' => $salary,
        'groceries' => $groceries,
    ];
}

test('monthly report summarizes income and expenses by currency and excludes transfers and reversals', function (): void {
    $fixtures = reportFixtures();

    $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Income,
        financialAccountId: (int) $fixtures['checking']->id,
        amount: '10000.00',
        entryDate: '2026-11-02',
        description: 'Paycheck',
        idempotencyKey: (string) Str::uuid(),
        categoryId: (int) $fixtures['salary']->id,
    ));

    $expense = $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: (int) $fixtures['checking']->id,
        amount: '2500.00',
        entryDate: '2026-11-05',
        description: 'Rent',
        idempotencyKey: (string) Str::uuid(),
        categoryId: (int) $fixtures['rent']->id,
    ));

    $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: (int) $fixtures['checking']->id,
        amount: '500.00',
        entryDate: '2026-11-08',
        description: 'Groceries',
        idempotencyKey: (string) Str::uuid(),
        categoryId: (int) $fixtures['groceries']->id,
    ));

    $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Transfer,
        financialAccountId: (int) $fixtures['checking']->id,
        amount: '100.00',
        entryDate: '2026-11-09',
        description: 'Move cash',
        idempotencyKey: (string) Str::uuid(),
        transferFinancialAccountId: (int) $fixtures['savings']->id,
    ));

    $reversed = $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: (int) $fixtures['checking']->id,
        amount: '50.00',
        entryDate: '2026-11-10',
        description: 'Mistake',
        idempotencyKey: (string) Str::uuid(),
        categoryId: (int) $fixtures['groceries']->id,
    ));
    $fixtures['transactions']->reverseTransaction(new ReverseFinancialTransactionInput(
        user: $fixtures['user'],
        originalTransactionId: (int) $reversed->transaction->id,
        idempotencyKey: (string) Str::uuid(),
        reason: 'Correction',
    ));

    $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: (int) $fixtures['usd']->id,
        amount: '20.00',
        entryDate: '2026-11-11',
        description: 'USD snack',
        idempotencyKey: (string) Str::uuid(),
        categoryId: (int) $fixtures['groceries']->id,
    ));

    $otherChecking = $fixtures['accounts']->createAccount($fixtures['otherUser'], [
        'name' => 'Other',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);
    $otherExpense = $fixtures['categories']->createCategory($fixtures['otherUser'], [
        'name' => 'Other',
        'type' => CategoryType::Expense,
    ]);
    $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['otherUser'],
        type: FinancialTransactionType::Expense,
        financialAccountId: (int) $otherChecking->id,
        amount: '999.00',
        entryDate: '2026-11-12',
        description: 'Other spend',
        idempotencyKey: (string) Str::uuid(),
        categoryId: (int) $otherExpense->id,
    ));

    $report = $fixtures['reports']->build(new FinancialReportQuery(
        user: $fixtures['user'],
        mode: 'month',
        startDate: '2026-11-01',
        endDate: '2026-11-30',
        month: '2026-11',
    ));

    expect($report['empty']['has_period_activity'])->toBeTrue()
        ->and($report['period']['label'])->toBe('November 2026')
        ->and(count($report['currencies']))->toBe(2);

    $php = collect($report['currencies'])->firstWhere('currency_code', 'PHP');
    expect($php['summary']['income']['amount_minor'])->toBe('1000000')
        ->and($php['summary']['expenses']['amount_minor'])->toBe('300000')
        ->and($php['summary']['net']['amount_minor'])->toBe('700000')
        ->and($php['category_spending'][0]['category_name'])->toBe('Rent')
        ->and($php['category_spending'][0]['total']['amount_minor'])->toBe('250000')
        ->and($php['category_spending'][0]['share_bps'])->toBe(8333)
        ->and(count($php['cash_flow_trend']))->toBe(6)
        ->and($php['cash_flow_trend'][5]['period_key'])->toBe('2026-11');

    $usd = collect($report['currencies'])->firstWhere('currency_code', 'USD');
    expect($usd['summary']['expenses']['amount_minor'])->toBe('2000')
        ->and($usd['summary']['income']['amount_minor'])->toBe('0');

    expect($expense->transaction->id)->toBeInt();
});

test('custom range and account history respect ownership and active transactions', function (): void {
    $fixtures = reportFixtures();

    $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: (int) $fixtures['checking']->id,
        amount: '100.00',
        entryDate: '2026-10-20',
        description: 'In range',
        idempotencyKey: (string) Str::uuid(),
        categoryId: (int) $fixtures['rent']->id,
    ));

    $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: (int) $fixtures['checking']->id,
        amount: '40.00',
        entryDate: '2026-09-01',
        description: 'Out of range',
        idempotencyKey: (string) Str::uuid(),
        categoryId: (int) $fixtures['rent']->id,
    ));

    $report = $fixtures['reports']->build(new FinancialReportQuery(
        user: $fixtures['user'],
        mode: 'custom',
        startDate: '2026-10-01',
        endDate: '2026-10-31',
        financialAccountId: (int) $fixtures['checking']->id,
    ));

    expect($report['period']['mode'])->toBe('custom')
        ->and($report['currencies'][0]['summary']['expenses']['amount_minor'])->toBe('10000')
        ->and($report['account_history']['account']['id'])->toBe((int) $fixtures['checking']->id)
        ->and(count($report['account_history']['transactions']))->toBe(1)
        ->and($report['account_history']['transactions'][0]['description'])->toBe('In range')
        ->and($report['account_history']['transactions'][0]['amount_minor'])->toBe('10000');
});

test('guests cannot open reports and owners receive inertia props', function (): void {
    $fixtures = reportFixtures();

    $this->get(route('reports.index'))->assertRedirect(route('login'));

    $this->actingAs($fixtures['user'])
        ->get(route('reports.index', ['mode' => 'month', 'month' => '2026-11']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reports/Index')
            ->where('period.month', '2026-11')
            ->where('empty.has_accounts', true)
            ->has('accounts')
            ->has('currencies'));
});

test('custom ranges longer than 24 months include every intersecting month without truncation', function (): void {
    $fixtures = reportFixtures();

    $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: (int) $fixtures['checking']->id,
        amount: '10.00',
        entryDate: '2023-01-15',
        description: 'Early',
        idempotencyKey: (string) Str::uuid(),
        categoryId: (int) $fixtures['rent']->id,
    ));
    $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: (int) $fixtures['checking']->id,
        amount: '25.00',
        entryDate: '2025-06-20',
        description: 'Late',
        idempotencyKey: (string) Str::uuid(),
        categoryId: (int) $fixtures['groceries']->id,
    ));

    $report = $fixtures['reports']->build(new FinancialReportQuery(
        user: $fixtures['user'],
        mode: 'custom',
        startDate: '2023-01-01',
        endDate: '2025-06-30',
    ));

    $php = collect($report['currencies'])->firstWhere('currency_code', 'PHP');
    expect(count($php['cash_flow_trend']))->toBe(30)
        ->and($php['cash_flow_trend'][0]['period_key'])->toBe('2023-01')
        ->and($php['cash_flow_trend'][29]['period_key'])->toBe('2025-06')
        ->and($php['summary']['expenses']['amount_minor'])->toBe('3500');
});

test('custom ranges exceeding five years are rejected', function (): void {
    $fixtures = reportFixtures();

    expect(fn () => $fixtures['reports']->build(new FinancialReportQuery(
        user: $fixtures['user'],
        mode: 'custom',
        startDate: '2020-01-01',
        endDate: '2026-01-02',
    )))->toThrow(\App\Exceptions\LedgerIntegrityException::class, 'five years');

    $this->actingAs($fixtures['user'])
        ->get(route('reports.index', [
            'mode' => 'custom',
            'start_date' => '2020-01-01',
            'end_date' => '2026-01-02',
        ]))
        ->assertRedirect()
        ->assertSessionHasErrors('period');
});

test('inverted custom dates are rejected through the HTTP endpoint', function (): void {
    $fixtures = reportFixtures();

    $this->actingAs($fixtures['user'])
        ->from(route('reports.index'))
        ->get(route('reports.index', [
            'mode' => 'custom',
            'start_date' => '2026-11-30',
            'end_date' => '2026-11-01',
        ]))
        ->assertRedirect()
        ->assertSessionHasErrors('end_date');
});

test('account history rejects another users account', function (): void {
    $fixtures = reportFixtures();
    $otherChecking = $fixtures['accounts']->createAccount($fixtures['otherUser'], [
        'name' => 'Foreign',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);

    $this->actingAs($fixtures['user'])
        ->from(route('reports.index'))
        ->get(route('reports.index', [
            'mode' => 'month',
            'month' => '2026-11',
            'financial_account_id' => $otherChecking->id,
        ]))
        ->assertRedirect()
        ->assertSessionHasErrors('financial_account_id');
});

test('account history orders newest first and caps at fifty rows', function (): void {
    $fixtures = reportFixtures();

    for ($i = 1; $i <= 51; $i++) {
        $date = Carbon::parse('2026-08-01', 'UTC')->addDays($i - 1)->toDateString();
        $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
            user: $fixtures['user'],
            type: FinancialTransactionType::Expense,
            financialAccountId: (int) $fixtures['checking']->id,
            amount: '1.00',
            entryDate: $date,
            description: sprintf('History %02d', $i),
            idempotencyKey: (string) Str::uuid(),
            categoryId: (int) $fixtures['rent']->id,
        ));
    }

    $report = $fixtures['reports']->build(new FinancialReportQuery(
        user: $fixtures['user'],
        mode: 'custom',
        startDate: '2026-08-01',
        endDate: '2026-09-30',
        financialAccountId: (int) $fixtures['checking']->id,
    ));

    $history = $report['account_history'];
    expect($history['meta']['total'])->toBe(51)
        ->and($history['meta']['limit'])->toBe(50)
        ->and($history['meta']['capped'])->toBeTrue()
        ->and(count($history['transactions']))->toBe(50)
        ->and($history['transactions'][0]['description'])->toBe('History 51')
        ->and($history['transactions'][49]['description'])->toBe('History 02');
});

test('category spending reconciles with expense totals', function (): void {
    $fixtures = reportFixtures();

    $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: (int) $fixtures['checking']->id,
        amount: '100.00',
        entryDate: '2026-11-03',
        description: 'Rent',
        idempotencyKey: (string) Str::uuid(),
        categoryId: (int) $fixtures['rent']->id,
    ));
    $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: (int) $fixtures['checking']->id,
        amount: '40.00',
        entryDate: '2026-11-04',
        description: 'Food',
        idempotencyKey: (string) Str::uuid(),
        categoryId: (int) $fixtures['groceries']->id,
    ));

    $report = $fixtures['reports']->build(new FinancialReportQuery(
        user: $fixtures['user'],
        mode: 'month',
        startDate: '2026-11-01',
        endDate: '2026-11-30',
        month: '2026-11',
    ));

    $php = collect($report['currencies'])->firstWhere('currency_code', 'PHP');
    $categorySum = 0;
    foreach ($php['category_spending'] as $row) {
        $categorySum = Money::checkedAdd($categorySum, (int) $row['total']['amount_minor']);
        expect($row['category_name'])->not->toBe('Uncategorized');
    }

    expect($categorySum)->toBe((int) $php['summary']['expenses']['amount_minor'])
        ->and($php['summary']['expenses']['amount_minor'])->toBe('14000')
        ->and(count($php['category_spending']))->toBe(2);
});

test('uncategorized expense bucket aggregates null category_id rows defensively', function (): void {
    // Schema CHECK forbids null category_id on expense inserts; exercise the report
    // aggregator's defensive Uncategorized path with in-memory rows instead.
    $fixtures = reportFixtures();
    $row = new \App\Models\FinancialTransaction([
        'currency_code' => 'PHP',
        'entry_date' => '2026-11-06',
        'amount_minor' => 7500,
        'type' => FinancialTransactionType::Expense,
        'category_id' => null,
        'status' => 'active',
    ]);

    $method = new ReflectionMethod(FinancialReportService::class, 'buildCurrencySections');
    $method->setAccessible(true);
    $sections = $method->invoke(
        $fixtures['reports'],
        collect([$row]),
        '2026-11-01',
        '2026-11-30',
        [['key' => '2026-11', 'label' => 'Nov 2026', 'start' => '2026-11-01', 'end' => '2026-11-30']],
        ['PHP' => ['symbol' => '₱', 'exponent' => 2]],
        [],
        'month',
    );

    $php = collect($sections)->firstWhere('currency_code', 'PHP');
    $uncategorized = collect($php['category_spending'])->firstWhere('category_name', 'Uncategorized');

    expect($uncategorized)->not->toBeNull()
        ->and($uncategorized['category_id'])->toBeNull()
        ->and($uncategorized['total']['amount_minor'])->toBe('7500')
        ->and($php['summary']['expenses']['amount_minor'])->toBe('7500');
});

test('monthly report cash flow matches dashboard for the equivalent UTC month', function (): void {
    $fixtures = reportFixtures();

    $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Income,
        financialAccountId: (int) $fixtures['checking']->id,
        amount: '5000.00',
        entryDate: '2026-11-02',
        description: 'Pay',
        idempotencyKey: (string) Str::uuid(),
        categoryId: (int) $fixtures['salary']->id,
    ));
    $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: (int) $fixtures['checking']->id,
        amount: '1200.00',
        entryDate: '2026-11-08',
        description: 'Rent',
        idempotencyKey: (string) Str::uuid(),
        categoryId: (int) $fixtures['rent']->id,
    ));
    $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Transfer,
        financialAccountId: (int) $fixtures['checking']->id,
        amount: '50.00',
        entryDate: '2026-11-09',
        description: 'Transfer',
        idempotencyKey: (string) Str::uuid(),
        transferFinancialAccountId: (int) $fixtures['savings']->id,
    ));

    $report = $fixtures['reports']->build(new FinancialReportQuery(
        user: $fixtures['user'],
        mode: 'month',
        startDate: '2026-11-01',
        endDate: '2026-11-30',
        month: '2026-11',
    ));
    $dashboard = app(DashboardOverviewService::class)->build($fixtures['user'])->toInertiaProps();

    $reportPhp = collect($report['currencies'])->firstWhere('currency_code', 'PHP');
    $dashPhp = collect($dashboard['currencies'])->firstWhere('currency_code', 'PHP');

    expect($reportPhp['summary']['income']['amount_minor'])->toBe($dashPhp['cash_flow']['income']['amount_minor'])
        ->and($reportPhp['summary']['expenses']['amount_minor'])->toBe($dashPhp['cash_flow']['expenses']['amount_minor'])
        ->and($reportPhp['summary']['net']['amount_minor'])->toBe($dashPhp['cash_flow']['net']['amount_minor']);
});

test('custom range trend totals reconcile with the period summary including partial months', function (): void {
    $fixtures = reportFixtures();

    $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Income,
        financialAccountId: (int) $fixtures['checking']->id,
        amount: '300.00',
        entryDate: '2026-01-05',
        description: 'Before range',
        idempotencyKey: (string) Str::uuid(),
        categoryId: (int) $fixtures['salary']->id,
    ));
    $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Income,
        financialAccountId: (int) $fixtures['checking']->id,
        amount: '1000.00',
        entryDate: '2026-01-20',
        description: 'In first partial month',
        idempotencyKey: (string) Str::uuid(),
        categoryId: (int) $fixtures['salary']->id,
    ));
    $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: (int) $fixtures['checking']->id,
        amount: '200.00',
        entryDate: '2026-02-10',
        description: 'Middle',
        idempotencyKey: (string) Str::uuid(),
        categoryId: (int) $fixtures['rent']->id,
    ));
    $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: (int) $fixtures['checking']->id,
        amount: '50.00',
        entryDate: '2026-03-05',
        description: 'Last partial',
        idempotencyKey: (string) Str::uuid(),
        categoryId: (int) $fixtures['groceries']->id,
    ));
    $fixtures['transactions']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: (int) $fixtures['checking']->id,
        amount: '99.00',
        entryDate: '2026-03-25',
        description: 'After range',
        idempotencyKey: (string) Str::uuid(),
        categoryId: (int) $fixtures['groceries']->id,
    ));

    $report = $fixtures['reports']->build(new FinancialReportQuery(
        user: $fixtures['user'],
        mode: 'custom',
        startDate: '2026-01-15',
        endDate: '2026-03-10',
    ));

    $php = collect($report['currencies'])->firstWhere('currency_code', 'PHP');
    $trendIncome = 0;
    $trendExpenses = 0;
    foreach ($php['cash_flow_trend'] as $point) {
        $trendIncome = Money::checkedAdd($trendIncome, (int) $point['income']['amount_minor']);
        $trendExpenses = Money::checkedAdd($trendExpenses, (int) $point['expenses']['amount_minor']);
    }

    expect(count($php['cash_flow_trend']))->toBe(3)
        ->and($php['cash_flow_trend'][0]['period_key'])->toBe('2026-01')
        ->and($php['cash_flow_trend'][0]['income']['amount_minor'])->toBe('100000')
        ->and($trendIncome)->toBe((int) $php['summary']['income']['amount_minor'])
        ->and($trendExpenses)->toBe((int) $php['summary']['expenses']['amount_minor'])
        ->and($php['summary']['income']['amount_minor'])->toBe('100000')
        ->and($php['summary']['expenses']['amount_minor'])->toBe('25000');
});
