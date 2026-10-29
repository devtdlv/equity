<?php

use App\DataTransferObjects\CreateFinancialTransactionInput;
use App\DataTransferObjects\ReverseFinancialTransactionInput;
use App\Enums\CategoryType;
use App\Enums\FinancialTransactionType;
use App\Models\Budget;
use App\Models\BudgetAllocation;
use App\Models\Currency;
use App\Models\User;
use App\Services\CategoryService;
use App\Services\FinancialAccountService;
use App\Services\FinancialTransactionService;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CurrencySeeder::class);
    Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00', 'UTC'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('guests cannot access budget endpoints', function (): void {
    $user = User::factory()->create();
    $budget = Budget::query()->create([
        'user_id' => $user->id,
        'currency_code' => 'PHP',
        'period_start' => '2026-10-01',
        'period_end' => '2026-10-31',
    ]);
    $category = app(CategoryService::class)->createCategory($user, [
        'name' => 'Groceries',
        'type' => CategoryType::Expense,
    ]);

    $this->get(route('budgets.index'))->assertRedirect(route('login'));
    $this->post(route('budgets.store'), [])->assertRedirect(route('login'));
    $this->get(route('budgets.show', $budget))->assertRedirect(route('login'));
    $this->put(route('budgets.allocations.update', [$budget, $category]), [])
        ->assertRedirect(route('login'));

    $this->getJson(route('budgets.index'))->assertUnauthorized();
    $this->postJson(route('budgets.store'), [])->assertUnauthorized();
    $this->getJson(route('budgets.show', $budget))->assertUnauthorized();
    $this->putJson(route('budgets.allocations.update', [$budget, $category]), [])
        ->assertUnauthorized();
});

test('authenticated users can create list filter and paginate budgets with tenant isolation', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $octPhp = $this->actingAs($userA)->postJson(route('budgets.store'), [
        'currency_code' => 'php',
        'year' => 2026,
        'month' => 10,
    ]);
    $octPhp->assertCreated()
        ->assertJsonPath('meta.was_recently_created', true)
        ->assertJsonPath('data.currency_code', 'PHP')
        ->assertJsonPath('data.period_start', '2026-10-01')
        ->assertJsonPath('data.period_end', '2026-10-31');

    $this->actingAs($userA)->postJson(route('budgets.store'), [
        'currency_code' => 'USD',
        'year' => 2026,
        'month' => 10,
    ])->assertCreated();

    $this->actingAs($userA)->postJson(route('budgets.store'), [
        'currency_code' => 'PHP',
        'year' => 2026,
        'month' => 9,
    ])->assertCreated();

    $this->actingAs($userB)->postJson(route('budgets.store'), [
        'currency_code' => 'PHP',
        'year' => 2026,
        'month' => 10,
    ])->assertCreated();

    $list = $this->actingAs($userA)->getJson(route('budgets.index'));
    $list->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('data.0.period_start', '2026-10-01')
        ->assertJsonPath('data.0.currency_code', 'PHP')
        ->assertJsonPath('data.1.period_start', '2026-10-01')
        ->assertJsonPath('data.1.currency_code', 'USD')
        ->assertJsonPath('data.2.period_start', '2026-09-01');

    $ids = collect($list->json('data'))->pluck('id');
    expect($ids)->not->toContain(
        Budget::query()->where('user_id', $userB->id)->value('id'),
    );

    $filtered = $this->actingAs($userA)->getJson(route('budgets.index', [
        'currency_code' => 'USD',
    ]));
    $filtered->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('filters.currency_code', 'USD')
        ->assertJsonPath('data.0.currency_code', 'USD');

    $page = $this->actingAs($userA)->getJson(route('budgets.index', [
        'per_page' => 1,
        'page' => 2,
    ]));
    $page->assertOk()
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.last_page', 3)
        ->assertJsonCount(1, 'data');
});

test('duplicate monthly budgets return 409 and invalid create payloads return 422', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('budgets.store'), [
        'currency_code' => 'PHP',
        'year' => 2026,
        'month' => 10,
    ])->assertCreated();

    $this->actingAs($user)->postJson(route('budgets.store'), [
        'currency_code' => 'PHP',
        'year' => 2026,
        'month' => 10,
    ])->assertStatus(409)
        ->assertJsonPath('errors.budget.0', fn ($message) => str_contains((string) $message, 'already exists'));

    expect(Budget::query()->where('user_id', $user->id)->count())->toBe(1);

    Currency::query()->where('code', 'USD')->update(['is_active' => false]);

    $this->actingAs($user)->postJson(route('budgets.store'), [
        'currency_code' => 'USD',
        'year' => 2026,
        'month' => 10,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('currency_code');

    $this->actingAs($user)->postJson(route('budgets.store'), [
        'currency_code' => 'PHP',
        'year' => 2026,
        'month' => 13,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('month');

    $this->actingAs($user)->postJson(route('budgets.store'), [
        'currency_code' => 'PHP',
        'year' => 2026,
        'month' => 0,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('month');
});

test('budget detail returns authoritative spending summary including zero allocations unbudgeted and reversals', function (): void {
    $user = User::factory()->create();
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
    $utilities = $categoryService->createCategory($user, [
        'name' => 'Utilities',
        'type' => CategoryType::Expense,
    ]);
    $salary = $categoryService->createCategory($user, [
        'name' => 'Salary',
        'type' => CategoryType::Income,
    ]);
    $checking = $accountService->createAccount($user, [
        'name' => 'Checking',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);
    $savings = $accountService->createAccount($user, [
        'name' => 'Savings',
        'type' => 'savings',
        'currency_code' => 'PHP',
    ]);

    $budgetId = (int) $this->actingAs($user)->postJson(route('budgets.store'), [
        'currency_code' => 'PHP',
        'year' => 2026,
        'month' => 10,
    ])->json('data.id');

    $this->actingAs($user)->putJson(route('budgets.allocations.update', [$budgetId, $groceries->id]), [
        'amount' => '100.00',
    ])->assertCreated();

    $this->actingAs($user)->putJson(route('budgets.allocations.update', [$budgetId, $dining->id]), [
        'amount' => '0',
    ])->assertCreated()
        ->assertJsonPath('data.allocation.amount_minor', 0)
        ->assertJsonPath('meta.was_recently_created', true);

    $expense = $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $checking->id,
        amount: '40.00',
        entryDate: '2026-10-05',
        description: 'Market',
        idempotencyKey: '11111111-aaaa-4111-8111-111111111111',
        categoryId: $groceries->id,
    ))->transaction;

    $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $checking->id,
        amount: '25.00',
        entryDate: '2026-10-06',
        description: 'Dinner',
        idempotencyKey: '22222222-aaaa-4111-8111-111111111111',
        categoryId: $dining->id,
    ));

    $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $checking->id,
        amount: '15.00',
        entryDate: '2026-10-07',
        description: 'Power',
        idempotencyKey: '33333333-aaaa-4111-8111-111111111111',
        categoryId: $utilities->id,
    ));

    $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Income,
        financialAccountId: $checking->id,
        amount: '1000.00',
        entryDate: '2026-10-01',
        description: 'Pay',
        idempotencyKey: '44444444-aaaa-4111-8111-111111111111',
        categoryId: $salary->id,
    ));

    $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Transfer,
        financialAccountId: $checking->id,
        amount: '50.00',
        entryDate: '2026-10-08',
        description: 'Move',
        idempotencyKey: '55555555-aaaa-4111-8111-111111111111',
        transferFinancialAccountId: $savings->id,
    ));

    $show = $this->actingAs($user)->getJson(route('budgets.show', $budgetId));
    $show->assertOk()
        ->assertJsonPath('data.budget.id', $budgetId)
        ->assertJsonPath('data.budget.currency_code', 'PHP')
        ->assertJsonPath('data.totals.allocated_minor', 10000)
        ->assertJsonPath('data.totals.unbudgeted_actual_minor', 1500)
        ->assertJsonPath('data.totals.total_actual_minor', 8000);

    $allocations = collect($show->json('data.allocations'))->keyBy('category_id');
    expect($allocations[$groceries->id]['actual_minor'])->toBe(4000)
        ->and($allocations[$groceries->id]['remaining_minor'])->toBe(6000)
        ->and($allocations[$groceries->id]['is_over_limit'])->toBeFalse()
        ->and($allocations[$dining->id]['allocated_minor'])->toBe(0)
        ->and($allocations[$dining->id]['actual_minor'])->toBe(2500)
        ->and($allocations[$dining->id]['is_over_limit'])->toBeTrue();

    $transactionService->reverseTransaction(new ReverseFinancialTransactionInput(
        user: $user,
        originalTransactionId: $expense->id,
        idempotencyKey: '66666666-aaaa-4111-8111-111111111111',
        reason: 'Duplicate',
    ));

    $afterReverse = $this->actingAs($user)->getJson(route('budgets.show', $budgetId));
    $afterAllocations = collect($afterReverse->json('data.allocations'))->keyBy('category_id');
    expect($afterAllocations[$groceries->id]['actual_minor'])->toBe(0)
        ->and($afterReverse->json('data.totals.total_actual_minor'))->toBe(4000);
});

test('allocation updates enforce ownership archived income past-month and currency rules without partial writes', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $categoryService = app(CategoryService::class);

    $groceries = $categoryService->createCategory($userA, [
        'name' => 'Groceries',
        'type' => CategoryType::Expense,
    ]);
    $dining = $categoryService->createCategory($userA, [
        'name' => 'Dining',
        'type' => CategoryType::Expense,
    ]);
    $salary = $categoryService->createCategory($userA, [
        'name' => 'Salary',
        'type' => CategoryType::Income,
    ]);
    $otherExpense = $categoryService->createCategory($userB, [
        'name' => 'Travel',
        'type' => CategoryType::Expense,
    ]);

    $budgetId = (int) $this->actingAs($userA)->postJson(route('budgets.store'), [
        'currency_code' => 'PHP',
        'year' => 2026,
        'month' => 10,
    ])->json('data.id');

    $create = $this->actingAs($userA)->putJson(route('budgets.allocations.update', [$budgetId, $groceries->id]), [
        'amount' => '50.00',
    ]);
    $create->assertCreated()
        ->assertJsonPath('data.allocation.amount_minor', 5000)
        ->assertJsonPath('data.previous_amount_minor', null)
        ->assertJsonPath('meta.was_recently_created', true)
        ->assertJsonPath('data.summary.totals.allocated_minor', 5000);

    $update = $this->actingAs($userA)->putJson(route('budgets.allocations.update', [$budgetId, $groceries->id]), [
        'amount' => '75.25',
    ]);
    $update->assertOk()
        ->assertJsonPath('data.allocation.amount_minor', 7525)
        ->assertJsonPath('data.previous_amount_minor', 5000)
        ->assertJsonPath('meta.was_recently_created', false);

    $this->actingAs($userA)->putJson(route('budgets.allocations.update', [$budgetId, $salary->id]), [
        'amount' => '10.00',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('category_id');

    expect(BudgetAllocation::query()->where('budget_id', $budgetId)->count())->toBe(1);

    $categoryService->archiveCategory($userA, $dining);
    $this->actingAs($userA)->putJson(route('budgets.allocations.update', [$budgetId, $dining->id]), [
        'amount' => '20.00',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('category_id');

    expect(BudgetAllocation::query()->where('budget_id', $budgetId)->where('category_id', $dining->id)->exists())
        ->toBeFalse();

    // Existing archived allocation remains editable
    $categoryService->archiveCategory($userA, $groceries);
    $this->actingAs($userA)->putJson(route('budgets.allocations.update', [$budgetId, $groceries->id]), [
        'amount' => '60.00',
    ])->assertOk()
        ->assertJsonPath('data.allocation.amount_minor', 6000);

    $this->actingAs($userA)->putJson(route('budgets.allocations.update', [$budgetId, $otherExpense->id]), [
        'amount' => '10.00',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('category_id');

    $otherBudgetId = (int) $this->actingAs($userB)->postJson(route('budgets.store'), [
        'currency_code' => 'PHP',
        'year' => 2026,
        'month' => 10,
    ])->json('data.id');

    $this->actingAs($userA)->getJson(route('budgets.show', $otherBudgetId))->assertForbidden();
    $this->actingAs($userA)->putJson(route('budgets.allocations.update', [$otherBudgetId, $groceries->id]), [
        'amount' => '1.00',
    ])->assertForbidden();

    $pastBudgetId = (int) $this->actingAs($userA)->postJson(route('budgets.store'), [
        'currency_code' => 'PHP',
        'year' => 2026,
        'month' => 9,
    ])->json('data.id');

    $this->actingAs($userA)->putJson(route('budgets.allocations.update', [$pastBudgetId, $dining->id]), [
        'amount' => '10.00',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('budget');

    expect(BudgetAllocation::query()->where('budget_id', $pastBudgetId)->count())->toBe(0);

    $this->actingAs($userA)->putJson(route('budgets.allocations.update', [$budgetId, $groceries->id]), [
        'amount' => '-1.00',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('amount');

    $this->actingAs($userA)->putJson(route('budgets.allocations.update', [$budgetId, $groceries->id]), [
        'amount' => '1.001',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('amount');
});

test('budget spending respects currency separation', function (): void {
    $user = User::factory()->create();
    $categoryService = app(CategoryService::class);
    $accountService = app(FinancialAccountService::class);
    $transactionService = app(FinancialTransactionService::class);

    $groceries = $categoryService->createCategory($user, [
        'name' => 'Groceries',
        'type' => CategoryType::Expense,
    ]);
    $phpAccount = $accountService->createAccount($user, [
        'name' => 'PHP Checking',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);
    $usdAccount = $accountService->createAccount($user, [
        'name' => 'USD Checking',
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);

    $phpBudgetId = (int) $this->actingAs($user)->postJson(route('budgets.store'), [
        'currency_code' => 'PHP',
        'year' => 2026,
        'month' => 10,
    ])->json('data.id');

    $this->actingAs($user)->putJson(route('budgets.allocations.update', [$phpBudgetId, $groceries->id]), [
        'amount' => '100.00',
    ])->assertCreated();

    $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $phpAccount->id,
        amount: '10.00',
        entryDate: '2026-10-10',
        description: 'PHP spend',
        idempotencyKey: '77777777-aaaa-4111-8111-111111111111',
        categoryId: $groceries->id,
    ));

    $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $usdAccount->id,
        amount: '25.00',
        entryDate: '2026-10-10',
        description: 'USD spend',
        idempotencyKey: '88888888-aaaa-4111-8111-111111111111',
        categoryId: $groceries->id,
    ));

    $this->actingAs($user)->getJson(route('budgets.show', $phpBudgetId))
        ->assertOk()
        ->assertJsonPath('data.allocations.0.actual_minor', 1000)
        ->assertJsonPath('data.totals.total_actual_minor', 1000);
});
