<?php

use App\Enums\CategoryType;
use App\Exceptions\ImmutableRecordException;
use App\Exceptions\LedgerIntegrityException;
use App\Models\Budget;
use App\Models\BudgetAllocation;
use App\Models\Category;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\CurrencySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CurrencySeeder::class);
});

/**
 * @return array{user: User, expense: Category, income: Category, archivedExpense: Category}
 */
function budgetSchemaFixtures(): array
{
    $user = User::factory()->create();

    $expense = Category::query()->create([
        'user_id' => $user->id,
        'name' => 'Groceries',
        'type' => CategoryType::Expense,
        'is_archived' => false,
        'archived_at' => null,
    ]);

    $income = Category::query()->create([
        'user_id' => $user->id,
        'name' => 'Salary',
        'type' => CategoryType::Income,
        'is_archived' => false,
        'archived_at' => null,
    ]);

    $archivedExpense = Category::query()->create([
        'user_id' => $user->id,
        'name' => 'Legacy Dining',
        'type' => CategoryType::Expense,
        'is_archived' => true,
        'archived_at' => now(),
    ]);

    return [
        'user' => $user,
        'expense' => $expense,
        'income' => $income,
        'archivedExpense' => $archivedExpense,
    ];
}

test('budgets and budget_allocations tables exist with expected columns', function (): void {
    expect(Schema::hasTable('budgets'))->toBeTrue()
        ->and(Schema::hasColumns('budgets', [
            'id',
            'user_id',
            'currency_code',
            'period_start',
            'period_end',
            'created_at',
            'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasTable('budget_allocations'))->toBeTrue()
        ->and(Schema::hasColumns('budget_allocations', [
            'id',
            'budget_id',
            'user_id',
            'category_id',
            'category_type',
            'amount_minor',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
});

test('valid calendar-month budgets can be created without allocations', function (): void {
    $fixtures = budgetSchemaFixtures();

    $budget = Budget::query()->create([
        'user_id' => $fixtures['user']->id,
        'currency_code' => 'PHP',
        'period_start' => '2026-10-01',
        'period_end' => '2026-10-31',
    ]);

    expect($budget->exists)->toBeTrue()
        ->and($budget->period_start->toDateString())->toBe('2026-10-01')
        ->and($budget->period_end->toDateString())->toBe('2026-10-31')
        ->and($budget->allocations)->toHaveCount(0)
        ->and($fixtures['user']->budgets)->toHaveCount(1)
        ->and($budget->user->is($fixtures['user']))->toBeTrue()
        ->and($budget->currency?->code)->toBe('PHP');
});

test('invalid budget start and end dates are rejected by model and database constraints', function (): void {
    $fixtures = budgetSchemaFixtures();
    $userId = $fixtures['user']->id;

    expect(fn () => Budget::query()->create([
        'user_id' => $userId,
        'currency_code' => 'PHP',
        'period_start' => '2026-10-02',
        'period_end' => '2026-10-31',
    ]))->toThrow(LedgerIntegrityException::class);

    expect(fn () => Budget::query()->create([
        'user_id' => $userId,
        'currency_code' => 'PHP',
        'period_start' => '2026-10-01',
        'period_end' => '2026-10-30',
    ]))->toThrow(LedgerIntegrityException::class);

    expect(fn () => Budget::query()->create([
        'user_id' => $userId,
        'currency_code' => 'PHP',
        'period_start' => '2026-10-01',
        'period_end' => '2026-11-30',
    ]))->toThrow(LedgerIntegrityException::class);

    expect(fn () => DB::table('budgets')->insert([
        'user_id' => $userId,
        'currency_code' => 'PHP',
        'period_start' => '2026-10-15',
        'period_end' => '2026-10-31',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // Non-leap February cannot end on the 29th
    expect(fn () => DB::table('budgets')->insert([
        'user_id' => $userId,
        'currency_code' => 'USD',
        'period_start' => '2026-02-01',
        'period_end' => '2026-02-29',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // Leap-year February is valid
    $leapBudget = Budget::query()->create([
        'user_id' => $userId,
        'currency_code' => 'PHP',
        'period_start' => '2024-02-01',
        'period_end' => '2024-02-29',
    ]);
    expect($leapBudget->exists)->toBeTrue();
});

test('duplicate monthly budgets for the same user and currency are rejected while multiple currencies and users are allowed', function (): void {
    $fixtures = budgetSchemaFixtures();
    $otherUser = User::factory()->create();

    Budget::query()->create([
        'user_id' => $fixtures['user']->id,
        'currency_code' => 'PHP',
        'period_start' => '2026-10-01',
        'period_end' => '2026-10-31',
    ]);

    expect(fn () => Budget::query()->create([
        'user_id' => $fixtures['user']->id,
        'currency_code' => 'PHP',
        'period_start' => '2026-10-01',
        'period_end' => '2026-10-31',
    ]))->toThrow(QueryException::class);

    $usdBudget = Budget::query()->create([
        'user_id' => $fixtures['user']->id,
        'currency_code' => 'USD',
        'period_start' => '2026-10-01',
        'period_end' => '2026-10-31',
    ]);
    expect($usdBudget->exists)->toBeTrue();

    $otherUserBudget = Budget::query()->create([
        'user_id' => $otherUser->id,
        'currency_code' => 'PHP',
        'period_start' => '2026-10-01',
        'period_end' => '2026-10-31',
    ]);
    expect($otherUserBudget->exists)->toBeTrue();
});

test('zero allocations are allowed while negative and above-maximum amounts are rejected', function (): void {
    $fixtures = budgetSchemaFixtures();

    $budget = Budget::query()->create([
        'user_id' => $fixtures['user']->id,
        'currency_code' => 'PHP',
        'period_start' => '2026-10-01',
        'period_end' => '2026-10-31',
    ]);

    $zeroAllocation = BudgetAllocation::query()->create([
        'budget_id' => $budget->id,
        'user_id' => $fixtures['user']->id,
        'category_id' => $fixtures['expense']->id,
        'category_type' => CategoryType::Expense,
        'amount_minor' => 0,
    ]);

    expect($zeroAllocation->amount_minor)->toBe(0)
        ->and($zeroAllocation->category_type)->toBe(CategoryType::Expense);

    expect(fn () => BudgetAllocation::query()->create([
        'budget_id' => $budget->id,
        'user_id' => $fixtures['user']->id,
        'category_id' => $fixtures['archivedExpense']->id,
        'category_type' => CategoryType::Expense,
        'amount_minor' => -1,
    ]))->toThrow(LedgerIntegrityException::class);

    expect(fn () => BudgetAllocation::query()->create([
        'budget_id' => $budget->id,
        'user_id' => $fixtures['user']->id,
        'category_id' => $fixtures['archivedExpense']->id,
        'category_type' => CategoryType::Expense,
        'amount_minor' => Money::MAX_MINOR_AMOUNT + 1,
    ]))->toThrow(LedgerIntegrityException::class);

    expect(fn () => DB::table('budget_allocations')->insert([
        'budget_id' => $budget->id,
        'user_id' => $fixtures['user']->id,
        'category_id' => $fixtures['archivedExpense']->id,
        'category_type' => 'expense',
        'amount_minor' => -5,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('budget_allocations')->insert([
        'budget_id' => $budget->id,
        'user_id' => $fixtures['user']->id,
        'category_id' => $fixtures['archivedExpense']->id,
        'category_type' => 'expense',
        'amount_minor' => Money::MAX_MINOR_AMOUNT + 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    $maxAllocation = BudgetAllocation::query()->create([
        'budget_id' => $budget->id,
        'user_id' => $fixtures['user']->id,
        'category_id' => $fixtures['archivedExpense']->id,
        'category_type' => CategoryType::Expense,
        'amount_minor' => Money::MAX_MINOR_AMOUNT,
    ]);
    expect($maxAllocation->amount_minor)->toBe(Money::MAX_MINOR_AMOUNT);
});

test('duplicate category allocations on the same budget are rejected', function (): void {
    $fixtures = budgetSchemaFixtures();

    $budget = Budget::query()->create([
        'user_id' => $fixtures['user']->id,
        'currency_code' => 'PHP',
        'period_start' => '2026-11-01',
        'period_end' => '2026-11-30',
    ]);

    BudgetAllocation::query()->create([
        'budget_id' => $budget->id,
        'user_id' => $fixtures['user']->id,
        'category_id' => $fixtures['expense']->id,
        'category_type' => CategoryType::Expense,
        'amount_minor' => 25_000,
    ]);

    expect(fn () => BudgetAllocation::query()->create([
        'budget_id' => $budget->id,
        'user_id' => $fixtures['user']->id,
        'category_id' => $fixtures['expense']->id,
        'category_type' => CategoryType::Expense,
        'amount_minor' => 10_000,
    ]))->toThrow(QueryException::class);
});

test('cross-tenant budget allocations are rejected by composite foreign keys', function (): void {
    $fixtures = budgetSchemaFixtures();
    $otherUser = User::factory()->create();

    $otherExpense = Category::query()->create([
        'user_id' => $otherUser->id,
        'name' => 'Travel',
        'type' => CategoryType::Expense,
        'is_archived' => false,
        'archived_at' => null,
    ]);

    $budget = Budget::query()->create([
        'user_id' => $fixtures['user']->id,
        'currency_code' => 'PHP',
        'period_start' => '2026-12-01',
        'period_end' => '2026-12-31',
    ]);

    $otherBudget = Budget::query()->create([
        'user_id' => $otherUser->id,
        'currency_code' => 'PHP',
        'period_start' => '2026-12-01',
        'period_end' => '2026-12-31',
    ]);

    // Category owned by another user
    expect(fn () => DB::table('budget_allocations')->insert([
        'budget_id' => $budget->id,
        'user_id' => $fixtures['user']->id,
        'category_id' => $otherExpense->id,
        'category_type' => 'expense',
        'amount_minor' => 1_000,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // Budget owned by another user while claiming this user's id
    expect(fn () => DB::table('budget_allocations')->insert([
        'budget_id' => $otherBudget->id,
        'user_id' => $fixtures['user']->id,
        'category_id' => $fixtures['expense']->id,
        'category_type' => 'expense',
        'amount_minor' => 1_000,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // Mismatched user_id against own budget/category pair
    expect(fn () => DB::table('budget_allocations')->insert([
        'budget_id' => $budget->id,
        'user_id' => $otherUser->id,
        'category_id' => $fixtures['expense']->id,
        'category_type' => 'expense',
        'amount_minor' => 1_000,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('income category allocations are rejected while expense and archived expense categories are accepted', function (): void {
    $fixtures = budgetSchemaFixtures();

    $budget = Budget::query()->create([
        'user_id' => $fixtures['user']->id,
        'currency_code' => 'PHP',
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
    ]);

    // Income category cannot satisfy expense-typed composite FK
    expect(fn () => DB::table('budget_allocations')->insert([
        'budget_id' => $budget->id,
        'user_id' => $fixtures['user']->id,
        'category_id' => $fixtures['income']->id,
        'category_type' => 'expense',
        'amount_minor' => 5_000,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // category_type must remain expense at the CHECK layer
    expect(fn () => DB::table('budget_allocations')->insert([
        'budget_id' => $budget->id,
        'user_id' => $fixtures['user']->id,
        'category_id' => $fixtures['income']->id,
        'category_type' => 'income',
        'amount_minor' => 5_000,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    $expenseAllocation = BudgetAllocation::query()->create([
        'budget_id' => $budget->id,
        'user_id' => $fixtures['user']->id,
        'category_id' => $fixtures['expense']->id,
        'category_type' => CategoryType::Expense,
        'amount_minor' => 12_500,
    ]);

    $archivedAllocation = BudgetAllocation::query()->create([
        'budget_id' => $budget->id,
        'user_id' => $fixtures['user']->id,
        'category_id' => $fixtures['archivedExpense']->id,
        'category_type' => CategoryType::Expense,
        'amount_minor' => 7_500,
    ]);

    expect($expenseAllocation->category->is($fixtures['expense']))->toBeTrue()
        ->and($archivedAllocation->category->is($fixtures['archivedExpense']))->toBeTrue()
        ->and($fixtures['expense']->budgetAllocations)->toHaveCount(1)
        ->and($budget->fresh()?->allocations)->toHaveCount(2);
});

test('budget foreign-key integrity rejects unknown users and currencies', function (): void {
    $fixtures = budgetSchemaFixtures();

    expect(fn () => DB::table('budgets')->insert([
        'user_id' => 9_999_999,
        'currency_code' => 'PHP',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('budgets')->insert([
        'user_id' => $fixtures['user']->id,
        'currency_code' => 'ZZZ',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('budget and allocation models expose relationships, casts, and ownership immutability', function (): void {
    $fixtures = budgetSchemaFixtures();

    $budget = Budget::query()->create([
        'user_id' => $fixtures['user']->id,
        'currency_code' => 'USD',
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
    ]);

    $allocation = BudgetAllocation::query()->create([
        'budget_id' => $budget->id,
        'user_id' => $fixtures['user']->id,
        'category_id' => $fixtures['expense']->id,
        'amount_minor' => 42_00,
    ]);

    expect($allocation->category_type)->toBe(CategoryType::Expense)
        ->and($allocation->amount_minor)->toBe(4200)
        ->and($allocation->budget->is($budget))->toBeTrue()
        ->and($allocation->user->is($fixtures['user']))->toBeTrue()
        ->and($budget->period_start)->toBeInstanceOf(Carbon::class);

    $otherUser = User::factory()->create();

    expect(fn () => $budget->update(['user_id' => $otherUser->id]))
        ->toThrow(ImmutableRecordException::class);

    expect(fn () => $budget->update(['currency_code' => 'PHP']))
        ->toThrow(ImmutableRecordException::class);

    expect(fn () => $budget->update([
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
    ]))->toThrow(ImmutableRecordException::class);

    expect(fn () => $allocation->update(['user_id' => $otherUser->id]))
        ->toThrow(ImmutableRecordException::class);

    $allocation->refresh();

    expect(fn () => $allocation->update(['category_id' => $fixtures['archivedExpense']->id]))
        ->toThrow(ImmutableRecordException::class);

    $allocation->refresh();
    $allocation->update(['amount_minor' => 99_00]);
    expect($allocation->fresh()?->amount_minor)->toBe(9900);
});
