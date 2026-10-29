<?php

use App\DataTransferObjects\CreateBudgetInput;
use App\DataTransferObjects\CreateFinancialTransactionInput;
use App\DataTransferObjects\ReverseFinancialTransactionInput;
use App\DataTransferObjects\SetBudgetAllocationInput;
use App\Enums\CategoryType;
use App\Enums\FinancialTransactionType;
use App\Exceptions\LedgerIntegrityException;
use App\Models\AuditEvent;
use App\Models\Budget;
use App\Models\BudgetAllocation;
use App\Models\Category;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\AuditService;
use App\Services\BudgetService;
use App\Services\CategoryService;
use App\Services\FinancialAccountService;
use App\Services\FinancialTransactionService;
use App\Support\Money;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CurrencySeeder::class);
    Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00', 'UTC'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array{
 *     user: User,
 *     otherUser: User,
 *     budgetService: BudgetService,
 *     categoryService: CategoryService,
 *     accountService: FinancialAccountService,
 *     transactionService: FinancialTransactionService,
 *     groceries: Category,
 *     dining: Category,
 *     salary: Category,
 *     checking: FinancialAccount
 * }
 */
function budgetServiceFixtures(): array
{
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $budgetService = app(BudgetService::class);
    $categoryService = app(CategoryService::class);
    $accountService = app(FinancialAccountService::class);
    $transactionService = app(FinancialTransactionService::class);

    $groceries = $categoryService->createCategory($user, [
        'name' => 'Groceries',
        'type' => CategoryType::Expense,
    ]);
    $dining = $categoryService->createCategory($user, [
        'name' => 'Dining',
        'type' => CategoryType::Expense,
    ]);
    $salary = $categoryService->createCategory($user, [
        'name' => 'Salary',
        'type' => CategoryType::Income,
    ]);
    $checking = $accountService->createAccount($user, [
        'name' => 'Primary Checking',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);

    return compact(
        'user',
        'otherUser',
        'budgetService',
        'categoryService',
        'accountService',
        'transactionService',
        'groceries',
        'dining',
        'salary',
        'checking',
    );
}

test('creates an empty monthly budget with active currency validation and audit', function (): void {
    $fixtures = budgetServiceFixtures();

    $result = $fixtures['budgetService']->createBudget(new CreateBudgetInput(
        user: $fixtures['user'],
        currencyCode: 'php',
        year: 2026,
        month: 10,
    ));

    expect($result->wasRecentlyCreated)->toBeTrue()
        ->and($result->budget->currency_code)->toBe('PHP')
        ->and($result->budget->period_start->toDateString())->toBe('2026-10-01')
        ->and($result->budget->period_end->toDateString())->toBe('2026-10-31')
        ->and($result->budget->allocations)->toHaveCount(0)
        ->and($fixtures['user']->budgets)->toHaveCount(1);

    $audit = AuditEvent::query()
        ->where('user_id', $fixtures['user']->id)
        ->where('action', AuditService::ACTION_BUDGET_CREATED)
        ->where('auditable_type', Budget::class)
        ->where('auditable_id', $result->budget->id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit?->metadata['period_start'] ?? null)->toBe('2026-10-01');
});

test('rejects duplicate monthly budgets, inactive currencies, and invalid months without overwriting', function (): void {
    $fixtures = budgetServiceFixtures();

    $fixtures['budgetService']->createBudget(new CreateBudgetInput(
        user: $fixtures['user'],
        currencyCode: 'PHP',
        year: 2026,
        month: 10,
    ));

    expect(fn () => $fixtures['budgetService']->createBudget(new CreateBudgetInput(
        user: $fixtures['user'],
        currencyCode: 'PHP',
        year: 2026,
        month: 10,
    )))->toThrow(LedgerIntegrityException::class, 'already exists');

    expect(Budget::query()->where('user_id', $fixtures['user']->id)->count())->toBe(1);

    Currency::query()->where('code', 'USD')->update(['is_active' => false]);

    expect(fn () => $fixtures['budgetService']->createBudget(new CreateBudgetInput(
        user: $fixtures['user'],
        currencyCode: 'USD',
        year: 2026,
        month: 10,
    )))->toThrow(LedgerIntegrityException::class, 'not an active supported currency');

    expect(fn () => $fixtures['budgetService']->createBudget(new CreateBudgetInput(
        user: $fixtures['user'],
        currencyCode: 'PHP',
        year: 2026,
        month: 13,
    )))->toThrow(LedgerIntegrityException::class, 'valid calendar month');
});

test('supports multiple currencies and isolates tenants for the same month', function (): void {
    $fixtures = budgetServiceFixtures();

    $phpBudget = $fixtures['budgetService']->createBudget(new CreateBudgetInput(
        user: $fixtures['user'],
        currencyCode: 'PHP',
        year: 2026,
        month: 10,
    ))->budget;

    $usdBudget = $fixtures['budgetService']->createBudget(new CreateBudgetInput(
        user: $fixtures['user'],
        currencyCode: 'USD',
        year: 2026,
        month: 10,
    ))->budget;

    $otherBudget = $fixtures['budgetService']->createBudget(new CreateBudgetInput(
        user: $fixtures['otherUser'],
        currencyCode: 'PHP',
        year: 2026,
        month: 10,
    ))->budget;

    expect($phpBudget->id)->not->toBe($usdBudget->id)
        ->and($otherBudget->user_id)->toBe($fixtures['otherUser']->id);

    expect(fn () => $fixtures['budgetService']->getBudgetSpending(
        $fixtures['otherUser'],
        $phpBudget->id,
    ))->toThrow(LedgerIntegrityException::class, 'not accessible');

    expect(fn () => $fixtures['budgetService']->setAllocation(new SetBudgetAllocationInput(
        user: $fixtures['otherUser'],
        budgetId: $phpBudget->id,
        categoryId: $fixtures['groceries']->id,
        amount: '100.00',
    )))->toThrow(LedgerIntegrityException::class, 'not accessible');
});

test('creates zero allocations, updates amounts, rejects income categories, and audits changes', function (): void {
    $fixtures = budgetServiceFixtures();

    $budget = $fixtures['budgetService']->createBudget(new CreateBudgetInput(
        user: $fixtures['user'],
        currencyCode: 'PHP',
        year: 2026,
        month: 10,
    ))->budget;

    $created = $fixtures['budgetService']->setAllocation(new SetBudgetAllocationInput(
        user: $fixtures['user'],
        budgetId: $budget->id,
        categoryId: $fixtures['groceries']->id,
        amount: '0',
    ));

    expect($created->wasRecentlyCreated)->toBeTrue()
        ->and($created->previousAmountMinor)->toBeNull()
        ->and($created->allocation->amount_minor)->toBe(0)
        ->and($created->allocation->category_type)->toBe(CategoryType::Expense);

    $updated = $fixtures['budgetService']->setAllocation(new SetBudgetAllocationInput(
        user: $fixtures['user'],
        budgetId: $budget->id,
        categoryId: $fixtures['groceries']->id,
        amount: '250.50',
    ));

    expect($updated->wasRecentlyCreated)->toBeFalse()
        ->and($updated->previousAmountMinor)->toBe(0)
        ->and($updated->allocation->amount_minor)->toBe(25050);

    expect(fn () => $fixtures['budgetService']->setAllocation(new SetBudgetAllocationInput(
        user: $fixtures['user'],
        budgetId: $budget->id,
        categoryId: $fixtures['salary']->id,
        amount: '10.00',
    )))->toThrow(LedgerIntegrityException::class, 'Only expense categories');

    expect(fn () => $fixtures['budgetService']->setAllocation(new SetBudgetAllocationInput(
        user: $fixtures['user'],
        budgetId: $budget->id,
        categoryId: $fixtures['dining']->id,
        amount: '-1.00',
    )))->toThrow(LedgerIntegrityException::class);

    $allocationAudits = AuditEvent::query()
        ->where('user_id', $fixtures['user']->id)
        ->where('action', AuditService::ACTION_BUDGET_ALLOCATION_SET)
        ->orderBy('id')
        ->get();

    expect($allocationAudits)->toHaveCount(2)
        ->and($allocationAudits[0]->metadata['previous_amount_minor'])->toBeNull()
        ->and($allocationAudits[0]->metadata['new_amount_minor'])->toBe(0)
        ->and($allocationAudits[1]->metadata['previous_amount_minor'])->toBe(0)
        ->and($allocationAudits[1]->metadata['new_amount_minor'])->toBe(25050);
});

test('rejects new allocations for archived categories while preserving existing historical allocations', function (): void {
    $fixtures = budgetServiceFixtures();

    $budget = $fixtures['budgetService']->createBudget(new CreateBudgetInput(
        user: $fixtures['user'],
        currencyCode: 'PHP',
        year: 2026,
        month: 10,
    ))->budget;

    $fixtures['budgetService']->setAllocation(new SetBudgetAllocationInput(
        user: $fixtures['user'],
        budgetId: $budget->id,
        categoryId: $fixtures['groceries']->id,
        amount: '100.00',
    ));

    $fixtures['categoryService']->archiveCategory($fixtures['user'], $fixtures['groceries']);
    $fixtures['categoryService']->archiveCategory($fixtures['user'], $fixtures['dining']);

    $updated = $fixtures['budgetService']->setAllocation(new SetBudgetAllocationInput(
        user: $fixtures['user'],
        budgetId: $budget->id,
        categoryId: $fixtures['groceries']->id,
        amount: '80.00',
    ));

    expect($updated->allocation->amount_minor)->toBe(8000);

    expect(fn () => $fixtures['budgetService']->setAllocation(new SetBudgetAllocationInput(
        user: $fixtures['user'],
        budgetId: $budget->id,
        categoryId: $fixtures['dining']->id,
        amount: '20.00',
    )))->toThrow(LedgerIntegrityException::class, 'Archived categories cannot receive new');

    $summary = $fixtures['budgetService']->getBudgetSpending($fixtures['user'], $budget->id);
    expect($summary->allocatedCategories)->toHaveCount(1)
        ->and($summary->allocatedCategories[0]->categoryIsArchived)->toBeTrue()
        ->and($summary->allocatedCategories[0]->allocatedMinor)->toBe(8000);
});

test('rejects allocation edits for past months while allowing current and future months', function (): void {
    $fixtures = budgetServiceFixtures();

    $pastBudget = $fixtures['budgetService']->createBudget(new CreateBudgetInput(
        user: $fixtures['user'],
        currencyCode: 'PHP',
        year: 2026,
        month: 9,
    ))->budget;

    $futureBudget = $fixtures['budgetService']->createBudget(new CreateBudgetInput(
        user: $fixtures['user'],
        currencyCode: 'PHP',
        year: 2026,
        month: 11,
    ))->budget;

    expect(fn () => $fixtures['budgetService']->setAllocation(new SetBudgetAllocationInput(
        user: $fixtures['user'],
        budgetId: $pastBudget->id,
        categoryId: $fixtures['groceries']->id,
        amount: '50.00',
    )))->toThrow(LedgerIntegrityException::class, 'Past-month budgets are read-only');

    $futureAllocation = $fixtures['budgetService']->setAllocation(new SetBudgetAllocationInput(
        user: $fixtures['user'],
        budgetId: $futureBudget->id,
        categoryId: $fixtures['groceries']->id,
        amount: '50.00',
    ));

    expect($futureAllocation->allocation->amount_minor)->toBe(5000);
});

test('calculates allocated actuals, remaining, over-limit, and unbudgeted spending with integer arithmetic', function (): void {
    $fixtures = budgetServiceFixtures();

    $budget = $fixtures['budgetService']->createBudget(new CreateBudgetInput(
        user: $fixtures['user'],
        currencyCode: 'PHP',
        year: 2026,
        month: 10,
    ))->budget;

    $fixtures['budgetService']->setAllocation(new SetBudgetAllocationInput(
        user: $fixtures['user'],
        budgetId: $budget->id,
        categoryId: $fixtures['groceries']->id,
        amount: '100.00',
    ));

    // Explicit zero limit for dining: not unbudgeted
    $fixtures['budgetService']->setAllocation(new SetBudgetAllocationInput(
        user: $fixtures['user'],
        budgetId: $budget->id,
        categoryId: $fixtures['dining']->id,
        amount: '0',
    ));

    $utilities = $fixtures['categoryService']->createCategory($fixtures['user'], [
        'name' => 'Utilities',
        'type' => CategoryType::Expense,
    ]);

    $fixtures['transactionService']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: $fixtures['checking']->id,
        amount: '40.00',
        entryDate: '2026-10-05',
        description: 'Market',
        idempotencyKey: 'aaaaaaaa-1111-4111-8111-111111111111',
        categoryId: $fixtures['groceries']->id,
    ));

    $fixtures['transactionService']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: $fixtures['checking']->id,
        amount: '70.00',
        entryDate: '2026-10-06',
        description: 'Dinner',
        idempotencyKey: 'aaaaaaaa-2222-4111-8111-111111111111',
        categoryId: $fixtures['dining']->id,
    ));

    $fixtures['transactionService']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: $fixtures['checking']->id,
        amount: '15.25',
        entryDate: '2026-10-07',
        description: 'Power bill',
        idempotencyKey: 'aaaaaaaa-3333-4111-8111-111111111111',
        categoryId: $utilities->id,
    ));

    // Income and transfer must not count
    $fixtures['transactionService']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Income,
        financialAccountId: $fixtures['checking']->id,
        amount: '1000.00',
        entryDate: '2026-10-01',
        description: 'Pay',
        idempotencyKey: 'aaaaaaaa-4444-4111-8111-111111111111',
        categoryId: $fixtures['salary']->id,
    ));

    $savings = $fixtures['accountService']->createAccount($fixtures['user'], [
        'name' => 'Savings',
        'type' => 'savings',
        'currency_code' => 'PHP',
    ]);

    $fixtures['transactionService']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Transfer,
        financialAccountId: $fixtures['checking']->id,
        amount: '50.00',
        entryDate: '2026-10-08',
        description: 'Move',
        idempotencyKey: 'aaaaaaaa-5555-4111-8111-111111111111',
        transferFinancialAccountId: $savings->id,
    ));

    // Outside month excluded
    $fixtures['transactionService']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: $fixtures['checking']->id,
        amount: '99.00',
        entryDate: '2026-09-30',
        description: 'September spend',
        idempotencyKey: 'aaaaaaaa-6666-4111-8111-111111111111',
        categoryId: $fixtures['groceries']->id,
    ));

    $summary = $fixtures['budgetService']->getBudgetSpending($fixtures['user'], $budget->id);
    $byCategory = collect($summary->allocatedCategories)->keyBy('categoryId');

    expect($byCategory[$fixtures['groceries']->id]->actualMinor)->toBe(4000)
        ->and($byCategory[$fixtures['groceries']->id]->allocatedMinor)->toBe(10000)
        ->and($byCategory[$fixtures['groceries']->id]->remainingMinor)->toBe(6000)
        ->and($byCategory[$fixtures['groceries']->id]->isOverLimit)->toBeFalse()
        ->and($byCategory[$fixtures['dining']->id]->actualMinor)->toBe(7000)
        ->and($byCategory[$fixtures['dining']->id]->allocatedMinor)->toBe(0)
        ->and($byCategory[$fixtures['dining']->id]->remainingMinor)->toBe(-7000)
        ->and($byCategory[$fixtures['dining']->id]->isOverLimit)->toBeTrue()
        ->and($summary->unbudgetedActualMinor)->toBe(1525)
        ->and($summary->totalAllocatedMinor)->toBe(10000)
        ->and($summary->allocatedActualMinor)->toBe(11000)
        ->and($summary->totalActualMinor)->toBe(12525);
});

test('excludes reversed expenses from actuals using entry_date month semantics', function (): void {
    $fixtures = budgetServiceFixtures();

    $budget = $fixtures['budgetService']->createBudget(new CreateBudgetInput(
        user: $fixtures['user'],
        currencyCode: 'PHP',
        year: 2026,
        month: 10,
    ))->budget;

    $fixtures['budgetService']->setAllocation(new SetBudgetAllocationInput(
        user: $fixtures['user'],
        budgetId: $budget->id,
        categoryId: $fixtures['groceries']->id,
        amount: '100.00',
    ));

    $expense = $fixtures['transactionService']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: $fixtures['checking']->id,
        amount: '35.00',
        entryDate: '2026-10-10',
        description: 'Produce',
        idempotencyKey: 'bbbbbbbb-1111-4111-8111-111111111111',
        categoryId: $fixtures['groceries']->id,
    ))->transaction;

    $before = $fixtures['budgetService']->getBudgetSpending($fixtures['user'], $budget->id);
    expect($before->allocatedCategories[0]->actualMinor)->toBe(3500);

    // Reverse later in wall-clock time; economic entry_date remains October
    Carbon::setTestNow(Carbon::parse('2026-11-02 09:00:00', 'UTC'));

    $fixtures['transactionService']->reverseTransaction(new ReverseFinancialTransactionInput(
        user: $fixtures['user'],
        originalTransactionId: $expense->id,
        idempotencyKey: 'bbbbbbbb-2222-4111-8111-111111111111',
        reason: 'Duplicate charge',
    ));

    $after = $fixtures['budgetService']->getBudgetSpending($fixtures['user'], $budget->id);
    expect($after->allocatedCategories[0]->actualMinor)->toBe(0)
        ->and($after->totalActualMinor)->toBe(0);

    // November budget must not absorb the reversal as spending
    $november = $fixtures['budgetService']->createBudget(new CreateBudgetInput(
        user: $fixtures['user'],
        currencyCode: 'PHP',
        year: 2026,
        month: 11,
    ))->budget;

    $fixtures['budgetService']->setAllocation(new SetBudgetAllocationInput(
        user: $fixtures['user'],
        budgetId: $november->id,
        categoryId: $fixtures['groceries']->id,
        amount: '100.00',
    ));

    $novemberSummary = $fixtures['budgetService']->getBudgetSpending($fixtures['user'], $november->id);
    expect($novemberSummary->allocatedCategories[0]->actualMinor)->toBe(0);
});

test('never combines multi-currency spending into a single budget', function (): void {
    $fixtures = budgetServiceFixtures();

    $phpBudget = $fixtures['budgetService']->createBudget(new CreateBudgetInput(
        user: $fixtures['user'],
        currencyCode: 'PHP',
        year: 2026,
        month: 10,
    ))->budget;

    $fixtures['budgetService']->setAllocation(new SetBudgetAllocationInput(
        user: $fixtures['user'],
        budgetId: $phpBudget->id,
        categoryId: $fixtures['groceries']->id,
        amount: '100.00',
    ));

    $usdAccount = $fixtures['accountService']->createAccount($fixtures['user'], [
        'name' => 'USD Checking',
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);

    $fixtures['transactionService']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: $usdAccount->id,
        amount: '25.00',
        entryDate: '2026-10-12',
        description: 'USD spend',
        idempotencyKey: 'cccccccc-1111-4111-8111-111111111111',
        categoryId: $fixtures['groceries']->id,
    ));

    $fixtures['transactionService']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: $fixtures['checking']->id,
        amount: '10.00',
        entryDate: '2026-10-12',
        description: 'PHP spend',
        idempotencyKey: 'cccccccc-2222-4111-8111-111111111111',
        categoryId: $fixtures['groceries']->id,
    ));

    $summary = $fixtures['budgetService']->getBudgetSpending($fixtures['user'], $phpBudget->id);
    expect($summary->allocatedCategories[0]->actualMinor)->toBe(1000)
        ->and($summary->totalActualMinor)->toBe(1000);
});

test('surfaces aggregate overflow without clamping monetary totals', function (): void {
    $fixtures = budgetServiceFixtures();

    $budget = $fixtures['budgetService']->createBudget(new CreateBudgetInput(
        user: $fixtures['user'],
        currencyCode: 'PHP',
        year: 2026,
        month: 10,
    ))->budget;

    $fixtures['budgetService']->setAllocation(new SetBudgetAllocationInput(
        user: $fixtures['user'],
        budgetId: $budget->id,
        categoryId: $fixtures['groceries']->id,
        amount: '1.00',
    ));

    $halfPlus = intdiv(Money::MAX_MINOR_AMOUNT, 2) + 1;
    $major = intdiv($halfPlus, 100);
    $fraction = $halfPlus % 100;
    $amountString = $major.'.'.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);

    $fixtures['transactionService']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: $fixtures['checking']->id,
        amount: $amountString,
        entryDate: '2026-10-01',
        description: 'Large A',
        idempotencyKey: 'dddddddd-1111-4111-8111-111111111111',
        categoryId: $fixtures['groceries']->id,
    ));

    $fixtures['transactionService']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: $fixtures['checking']->id,
        amount: $amountString,
        entryDate: '2026-10-02',
        description: 'Large B',
        idempotencyKey: 'dddddddd-2222-4111-8111-111111111111',
        categoryId: $fixtures['groceries']->id,
    ));

    expect(fn () => $fixtures['budgetService']->getBudgetSpending($fixtures['user'], $budget->id))
        ->toThrow(LedgerIntegrityException::class, 'overflow');
});

test('rolls back budget creation when audit persistence fails', function (): void {
    $user = User::factory()->create();

    $failingAudit = new class extends AuditService
    {
        public function record(
            User $actor,
            string $action,
            string $auditableType,
            int $auditableId,
            array $metadata = [],
        ): AuditEvent {
            throw new RuntimeException('Forced audit failure.');
        }
    };

    $budgetService = new BudgetService($failingAudit);

    expect(fn () => $budgetService->createBudget(new CreateBudgetInput(
        user: $user,
        currencyCode: 'PHP',
        year: 2026,
        month: 10,
    )))->toThrow(RuntimeException::class, 'Forced audit failure');

    expect(Budget::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('budgets')->count())->toBe(0);
});

test('rolls back allocation writes when audit persistence fails', function (): void {
    $fixtures = budgetServiceFixtures();

    $budget = $fixtures['budgetService']->createBudget(new CreateBudgetInput(
        user: $fixtures['user'],
        currencyCode: 'PHP',
        year: 2026,
        month: 10,
    ))->budget;

    $failingAudit = new class extends AuditService
    {
        public function record(
            User $actor,
            string $action,
            string $auditableType,
            int $auditableId,
            array $metadata = [],
        ): AuditEvent {
            throw new RuntimeException('Forced allocation audit failure.');
        }
    };

    $budgetService = new BudgetService($failingAudit);

    expect(fn () => $budgetService->setAllocation(new SetBudgetAllocationInput(
        user: $fixtures['user'],
        budgetId: $budget->id,
        categoryId: $fixtures['groceries']->id,
        amount: '25.00',
    )))->toThrow(RuntimeException::class, 'Forced allocation audit failure');

    expect(BudgetAllocation::query()->where('budget_id', $budget->id)->count())->toBe(0);
});
