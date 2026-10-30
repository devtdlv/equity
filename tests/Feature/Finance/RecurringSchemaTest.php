<?php

use App\DataTransferObjects\CreateFinancialTransactionInput;
use App\DataTransferObjects\ReverseFinancialTransactionInput;
use App\Enums\CategoryType;
use App\Enums\FinancialTransactionType;
use App\Enums\RecurringFrequency;
use App\Enums\RecurringObligationStatus;
use App\Enums\RecurringOccurrenceStatus;
use App\Exceptions\ImmutableRecordException;
use App\Exceptions\LedgerIntegrityException;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\RecurringObligation;
use App\Models\RecurringOccurrence;
use App\Models\RecurringPaymentLink;
use App\Models\User;
use App\Services\CategoryService;
use App\Services\FinancialAccountService;
use App\Services\FinancialTransactionService;
use App\Support\Money;
use Database\Seeders\CurrencySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CurrencySeeder::class);
});

/**
 * @return array{
 *     user: User,
 *     otherUser: User,
 *     expense: Category,
 *     income: Category,
 *     otherExpense: Category,
 *     phpAccount: FinancialAccount,
 *     usdAccount: FinancialAccount
 * }
 */
function recurringSchemaFixtures(): array
{
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $categoryService = app(CategoryService::class);
    $accountService = app(FinancialAccountService::class);

    $expense = $categoryService->createCategory($user, [
        'name' => 'Rent',
        'type' => CategoryType::Expense,
    ]);
    $income = $categoryService->createCategory($user, [
        'name' => 'Salary',
        'type' => CategoryType::Income,
    ]);
    $otherExpense = $categoryService->createCategory($otherUser, [
        'name' => 'Utilities',
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

    return compact(
        'user',
        'otherUser',
        'expense',
        'income',
        'otherExpense',
        'phpAccount',
        'usdAccount',
    );
}

/**
 * @param  array<string, mixed>  $overrides
 */
function createRecurringObligation(User $user, Category $expense, array $overrides = []): RecurringObligation
{
    return RecurringObligation::query()->create(array_merge([
        'user_id' => $user->id,
        'idempotency_key' => (string) Str::uuid(),
        'name' => 'Monthly Rent',
        'description' => null,
        'currency_code' => 'PHP',
        'amount_minor' => 15_000_00,
        'category_id' => $expense->id,
        'category_type' => CategoryType::Expense,
        'financial_account_id' => null,
        'start_date' => '2026-10-01',
        'frequency' => RecurringFrequency::Monthly,
        'interval_count' => 1,
        'anchor_day' => 1,
        'ends_of_month' => false,
        'next_occurrence_date' => '2026-10-01',
        'status' => RecurringObligationStatus::Active,
        'paused_at' => null,
        'cancelled_at' => null,
    ], $overrides));
}

test('recurring tables exist with expected columns and sqlite foreign keys enabled', function (): void {
    expect(Schema::hasTable('recurring_obligations'))->toBeTrue()
        ->and(Schema::hasTable('recurring_occurrences'))->toBeTrue()
        ->and(Schema::hasTable('recurring_payment_links'))->toBeTrue()
        ->and(Schema::hasColumns('recurring_obligations', [
            'id',
            'user_id',
            'idempotency_key',
            'name',
            'description',
            'currency_code',
            'amount_minor',
            'category_id',
            'category_type',
            'financial_account_id',
            'start_date',
            'frequency',
            'interval_count',
            'anchor_day',
            'ends_of_month',
            'next_occurrence_date',
            'status',
            'paused_at',
            'cancelled_at',
            'created_at',
            'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('recurring_occurrences', [
            'id',
            'user_id',
            'obligation_id',
            'due_date',
            'expected_amount_minor',
            'currency_code',
            'status',
            'active_slot_key',
            'skipped_at',
            'cancelled_at',
            'created_at',
            'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('recurring_payment_links', [
            'id',
            'user_id',
            'occurrence_id',
            'financial_transaction_id',
            'currency_code',
            'created_at',
            'updated_at',
        ]))->toBeTrue();

    expect(config('database.connections.sqlite.foreign_key_constraints'))->toBeTrue();

    $driver = Schema::getConnection()->getDriverName();
    if ($driver === 'sqlite') {
        $enabled = DB::select('PRAGMA foreign_keys');
        expect((int) ($enabled[0]->foreign_keys ?? 0))->toBe(1);
    }
});

test('valid recurring obligations can be created with relationships and casts', function (): void {
    $fixtures = recurringSchemaFixtures();

    $obligation = createRecurringObligation($fixtures['user'], $fixtures['expense'], [
        'financial_account_id' => $fixtures['phpAccount']->id,
        'frequency' => RecurringFrequency::Quarterly,
        'anchor_day' => 31,
        'ends_of_month' => true,
    ]);

    expect($obligation->exists)->toBeTrue()
        ->and($obligation->frequency)->toBe(RecurringFrequency::Quarterly)
        ->and($obligation->status)->toBe(RecurringObligationStatus::Active)
        ->and($obligation->category_type)->toBe(CategoryType::Expense)
        ->and($obligation->amount_minor)->toBe(15_000_00)
        ->and($obligation->user->is($fixtures['user']))->toBeTrue()
        ->and($obligation->category->is($fixtures['expense']))->toBeTrue()
        ->and($obligation->financialAccount?->is($fixtures['phpAccount']))->toBeTrue()
        ->and($fixtures['user']->recurringObligations)->toHaveCount(1);
});

test('duplicate user idempotency UUID is rejected while different users may share a UUID', function (): void {
    $fixtures = recurringSchemaFixtures();
    $sharedKey = '11111111-1111-4111-8111-111111111111';

    createRecurringObligation($fixtures['user'], $fixtures['expense'], [
        'idempotency_key' => $sharedKey,
    ]);

    expect(fn () => createRecurringObligation($fixtures['user'], $fixtures['expense'], [
        'idempotency_key' => $sharedKey,
        'name' => 'Duplicate attempt',
    ]))->toThrow(QueryException::class);

    $other = createRecurringObligation($fixtures['otherUser'], $fixtures['otherExpense'], [
        'idempotency_key' => $sharedKey,
        'name' => 'Other user bill',
    ]);
    expect($other->exists)->toBeTrue();
});

test('invalid money frequency status and category constraints are rejected', function (): void {
    $fixtures = recurringSchemaFixtures();

    expect(fn () => createRecurringObligation($fixtures['user'], $fixtures['expense'], [
        'amount_minor' => 0,
    ]))->toThrow(LedgerIntegrityException::class);

    expect(fn () => createRecurringObligation($fixtures['user'], $fixtures['expense'], [
        'amount_minor' => Money::MAX_MINOR_AMOUNT + 1,
    ]))->toThrow(LedgerIntegrityException::class);

    expect(fn () => DB::table('recurring_obligations')->insert([
        'user_id' => $fixtures['user']->id,
        'idempotency_key' => (string) Str::uuid(),
        'name' => 'Bad money',
        'description' => null,
        'currency_code' => 'PHP',
        'amount_minor' => -1,
        'category_id' => $fixtures['expense']->id,
        'category_type' => 'expense',
        'financial_account_id' => null,
        'start_date' => '2026-10-01',
        'frequency' => 'monthly',
        'interval_count' => 1,
        'anchor_day' => 1,
        'ends_of_month' => 0,
        'next_occurrence_date' => null,
        'status' => 'active',
        'paused_at' => null,
        'cancelled_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('recurring_obligations')->insert([
        'user_id' => $fixtures['user']->id,
        'idempotency_key' => (string) Str::uuid(),
        'name' => 'Bad frequency',
        'description' => null,
        'currency_code' => 'PHP',
        'amount_minor' => 1000,
        'category_id' => $fixtures['expense']->id,
        'category_type' => 'expense',
        'financial_account_id' => null,
        'start_date' => '2026-10-01',
        'frequency' => 'daily',
        'interval_count' => 1,
        'anchor_day' => 1,
        'ends_of_month' => 0,
        'next_occurrence_date' => null,
        'status' => 'active',
        'paused_at' => null,
        'cancelled_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('recurring_obligations')->insert([
        'user_id' => $fixtures['user']->id,
        'idempotency_key' => (string) Str::uuid(),
        'name' => 'Bad status',
        'description' => null,
        'currency_code' => 'PHP',
        'amount_minor' => 1000,
        'category_id' => $fixtures['expense']->id,
        'category_type' => 'expense',
        'financial_account_id' => null,
        'start_date' => '2026-10-01',
        'frequency' => 'monthly',
        'interval_count' => 1,
        'anchor_day' => 1,
        'ends_of_month' => 0,
        'next_occurrence_date' => null,
        'status' => 'archived',
        'paused_at' => null,
        'cancelled_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('recurring_obligations')->insert([
        'user_id' => $fixtures['user']->id,
        'idempotency_key' => (string) Str::uuid(),
        'name' => 'Income category',
        'description' => null,
        'currency_code' => 'PHP',
        'amount_minor' => 1000,
        'category_id' => $fixtures['income']->id,
        'category_type' => 'expense',
        'financial_account_id' => null,
        'start_date' => '2026-10-01',
        'frequency' => 'monthly',
        'interval_count' => 1,
        'anchor_day' => 1,
        'ends_of_month' => 0,
        'next_occurrence_date' => null,
        'status' => 'active',
        'paused_at' => null,
        'cancelled_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('recurring_obligations')->insert([
        'user_id' => $fixtures['user']->id,
        'idempotency_key' => (string) Str::uuid(),
        'name' => 'Cross tenant category',
        'description' => null,
        'currency_code' => 'PHP',
        'amount_minor' => 1000,
        'category_id' => $fixtures['otherExpense']->id,
        'category_type' => 'expense',
        'financial_account_id' => null,
        'start_date' => '2026-10-01',
        'frequency' => 'monthly',
        'interval_count' => 1,
        'anchor_day' => 1,
        'ends_of_month' => 0,
        'next_occurrence_date' => null,
        'status' => 'active',
        'paused_at' => null,
        'cancelled_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('preferred account must match tenant and currency when set', function (): void {
    $fixtures = recurringSchemaFixtures();
    $otherAccount = app(FinancialAccountService::class)->createAccount($fixtures['otherUser'], [
        'name' => 'Other Checking',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);

    expect(fn () => createRecurringObligation($fixtures['user'], $fixtures['expense'], [
        'financial_account_id' => $otherAccount->id,
    ]))->toThrow(QueryException::class);

    expect(fn () => createRecurringObligation($fixtures['user'], $fixtures['expense'], [
        'financial_account_id' => $fixtures['usdAccount']->id,
        'currency_code' => 'PHP',
    ]))->toThrow(QueryException::class);

    $valid = createRecurringObligation($fixtures['user'], $fixtures['expense'], [
        'financial_account_id' => $fixtures['usdAccount']->id,
        'currency_code' => 'USD',
        'amount_minor' => 50_00,
    ]);
    expect($valid->financial_account_id)->toBe($fixtures['usdAccount']->id);
});

test('occurrence creation enforces tenant currency amounts and stored statuses', function (): void {
    $fixtures = recurringSchemaFixtures();
    $obligation = createRecurringObligation($fixtures['user'], $fixtures['expense']);

    $occurrence = RecurringOccurrence::query()->create([
        'user_id' => $fixtures['user']->id,
        'obligation_id' => $obligation->id,
        'due_date' => '2026-10-01',
        'expected_amount_minor' => 15_000_00,
        'currency_code' => 'PHP',
        'status' => RecurringOccurrenceStatus::Scheduled,
        'skipped_at' => null,
        'cancelled_at' => null,
    ]);

    expect($occurrence->active_slot_key?->toDateString())->toBe('2026-10-01')
        ->and($occurrence->obligation->is($obligation))->toBeTrue()
        ->and($obligation->occurrences)->toHaveCount(1);

    expect(fn () => RecurringOccurrence::query()->create([
        'user_id' => $fixtures['otherUser']->id,
        'obligation_id' => $obligation->id,
        'due_date' => '2026-11-01',
        'expected_amount_minor' => 15_000_00,
        'currency_code' => 'PHP',
        'status' => RecurringOccurrenceStatus::Scheduled,
        'skipped_at' => null,
        'cancelled_at' => null,
    ]))->toThrow(QueryException::class);

    expect(fn () => RecurringOccurrence::query()->create([
        'user_id' => $fixtures['user']->id,
        'obligation_id' => $obligation->id,
        'due_date' => '2026-11-01',
        'expected_amount_minor' => 15_000_00,
        'currency_code' => 'USD',
        'status' => RecurringOccurrenceStatus::Scheduled,
        'skipped_at' => null,
        'cancelled_at' => null,
    ]))->toThrow(QueryException::class);

    expect(fn () => RecurringOccurrence::query()->create([
        'user_id' => $fixtures['user']->id,
        'obligation_id' => $obligation->id,
        'due_date' => '2026-12-01',
        'expected_amount_minor' => 0,
        'currency_code' => 'PHP',
        'status' => RecurringOccurrenceStatus::Scheduled,
        'skipped_at' => null,
        'cancelled_at' => null,
    ]))->toThrow(LedgerIntegrityException::class);

    expect(fn () => DB::table('recurring_occurrences')->insert([
        'user_id' => $fixtures['user']->id,
        'obligation_id' => $obligation->id,
        'due_date' => '2026-12-01',
        'expected_amount_minor' => 1000,
        'currency_code' => 'PHP',
        'status' => 'due',
        'active_slot_key' => '2026-12-01',
        'skipped_at' => null,
        'cancelled_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('active_slot_key preserves history while preventing duplicate live slots for skipped paid and scheduled', function (): void {
    $fixtures = recurringSchemaFixtures();
    $obligation = createRecurringObligation($fixtures['user'], $fixtures['expense']);

    $scheduled = RecurringOccurrence::query()->create([
        'user_id' => $fixtures['user']->id,
        'obligation_id' => $obligation->id,
        'due_date' => '2026-10-01',
        'expected_amount_minor' => 15_000_00,
        'currency_code' => 'PHP',
        'status' => RecurringOccurrenceStatus::Scheduled,
        'skipped_at' => null,
        'cancelled_at' => null,
    ]);

    // Duplicate current scheduled slot rejected (concurrent generation safety).
    expect(fn () => RecurringOccurrence::query()->create([
        'user_id' => $fixtures['user']->id,
        'obligation_id' => $obligation->id,
        'due_date' => '2026-10-01',
        'expected_amount_minor' => 15_000_00,
        'currency_code' => 'PHP',
        'status' => RecurringOccurrenceStatus::Scheduled,
        'skipped_at' => null,
        'cancelled_at' => null,
    ]))->toThrow(QueryException::class);

    // Paid slots remain status=scheduled and keep active_slot_key, so they are not reusable.
    expect($scheduled->fresh()?->active_slot_key?->toDateString())->toBe('2026-10-01');

    // Deliberate reschedule: cancel releases the slot and preserves history.
    $scheduled->status = RecurringOccurrenceStatus::Cancelled;
    $scheduled->cancelled_at = now();
    $scheduled->save();

    expect($scheduled->fresh()?->active_slot_key)->toBeNull()
        ->and(RecurringOccurrence::query()->whereKey($scheduled->id)->exists())->toBeTrue();

    $replacement = RecurringOccurrence::query()->create([
        'user_id' => $fixtures['user']->id,
        'obligation_id' => $obligation->id,
        'due_date' => '2026-10-01',
        'expected_amount_minor' => 16_000_00,
        'currency_code' => 'PHP',
        'status' => RecurringOccurrenceStatus::Scheduled,
        'skipped_at' => null,
        'cancelled_at' => null,
    ]);

    expect($replacement->id)->not->toBe($scheduled->id)
        ->and(RecurringOccurrence::query()->where('obligation_id', $obligation->id)->where('due_date', '2026-10-01')->count())->toBe(2);

    // Skip occupies the slot and blocks regeneration.
    $november = RecurringOccurrence::query()->create([
        'user_id' => $fixtures['user']->id,
        'obligation_id' => $obligation->id,
        'due_date' => '2026-11-01',
        'expected_amount_minor' => 15_000_00,
        'currency_code' => 'PHP',
        'status' => RecurringOccurrenceStatus::Skipped,
        'skipped_at' => now(),
        'cancelled_at' => null,
    ]);

    expect($november->active_slot_key?->toDateString())->toBe('2026-11-01');

    expect(fn () => RecurringOccurrence::query()->create([
        'user_id' => $fixtures['user']->id,
        'obligation_id' => $obligation->id,
        'due_date' => '2026-11-01',
        'expected_amount_minor' => 15_000_00,
        'currency_code' => 'PHP',
        'status' => RecurringOccurrenceStatus::Scheduled,
        'skipped_at' => null,
        'cancelled_at' => null,
    ]))->toThrow(QueryException::class);

    // Inconsistent occupancy rejected at database CHECK layer.
    expect(fn () => DB::table('recurring_occurrences')->insert([
        'user_id' => $fixtures['user']->id,
        'obligation_id' => $obligation->id,
        'due_date' => '2026-12-01',
        'expected_amount_minor' => 1000,
        'currency_code' => 'PHP',
        'status' => 'scheduled',
        'active_slot_key' => null,
        'skipped_at' => null,
        'cancelled_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('payment links enforce uniqueness tenant currency and survive transaction reversal', function (): void {
    $fixtures = recurringSchemaFixtures();
    $transactionService = app(FinancialTransactionService::class);

    $obligation = createRecurringObligation($fixtures['user'], $fixtures['expense'], [
        'financial_account_id' => $fixtures['phpAccount']->id,
    ]);

    $occurrence = RecurringOccurrence::query()->create([
        'user_id' => $fixtures['user']->id,
        'obligation_id' => $obligation->id,
        'due_date' => '2026-10-01',
        'expected_amount_minor' => 15_000_00,
        'currency_code' => 'PHP',
        'status' => RecurringOccurrenceStatus::Scheduled,
        'skipped_at' => null,
        'cancelled_at' => null,
    ]);

    $otherOccurrence = RecurringOccurrence::query()->create([
        'user_id' => $fixtures['user']->id,
        'obligation_id' => $obligation->id,
        'due_date' => '2026-11-01',
        'expected_amount_minor' => 15_000_00,
        'currency_code' => 'PHP',
        'status' => RecurringOccurrenceStatus::Scheduled,
        'skipped_at' => null,
        'cancelled_at' => null,
    ]);

    $expenseTx = $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: $fixtures['phpAccount']->id,
        amount: '100.00',
        entryDate: '2026-10-01',
        description: 'Rent payment',
        idempotencyKey: 'aaaaaaaa-1111-4111-8111-111111111111',
        categoryId: $fixtures['expense']->id,
    ))->transaction;

    $secondTx = $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: $fixtures['phpAccount']->id,
        amount: '50.00',
        entryDate: '2026-10-02',
        description: 'Partial rent',
        idempotencyKey: 'aaaaaaaa-2222-4111-8111-111111111111',
        categoryId: $fixtures['expense']->id,
    ))->transaction;

    $link = RecurringPaymentLink::query()->create([
        'user_id' => $fixtures['user']->id,
        'occurrence_id' => $occurrence->id,
        'financial_transaction_id' => $expenseTx->id,
        'currency_code' => 'PHP',
    ]);

    expect($link->financialTransaction?->is($expenseTx))->toBeTrue()
        ->and($expenseTx->fresh()?->recurringPaymentLink?->is($link))->toBeTrue()
        ->and($occurrence->paymentLinks)->toHaveCount(1);

    // One transaction cannot link to multiple occurrences.
    expect(fn () => RecurringPaymentLink::query()->create([
        'user_id' => $fixtures['user']->id,
        'occurrence_id' => $otherOccurrence->id,
        'financial_transaction_id' => $expenseTx->id,
        'currency_code' => 'PHP',
    ]))->toThrow(QueryException::class);

    // Duplicate pair rejected.
    expect(fn () => RecurringPaymentLink::query()->create([
        'user_id' => $fixtures['user']->id,
        'occurrence_id' => $occurrence->id,
        'financial_transaction_id' => $expenseTx->id,
        'currency_code' => 'PHP',
    ]))->toThrow(QueryException::class);

    // Cross-tenant link rejected (occurrence/user or transaction/user composite FK).
    expect(fn () => RecurringPaymentLink::query()->create([
        'user_id' => $fixtures['otherUser']->id,
        'occurrence_id' => $occurrence->id,
        'financial_transaction_id' => $secondTx->id,
        'currency_code' => 'PHP',
    ]))->toThrow(QueryException::class);

    // Currency/category/expense-type alignment is service-enforced (documented); schema stores
    // currency_code for later validation without coupling to migration 000010 indexes.
    RecurringPaymentLink::query()->create([
        'user_id' => $fixtures['user']->id,
        'occurrence_id' => $occurrence->id,
        'financial_transaction_id' => $secondTx->id,
        'currency_code' => 'PHP',
    ]);

    $transactionService->reverseTransaction(new ReverseFinancialTransactionInput(
        user: $fixtures['user'],
        originalTransactionId: $expenseTx->id,
        idempotencyKey: 'aaaaaaaa-3333-4111-8111-111111111111',
        reason: 'Correction',
    ));

    expect(RecurringPaymentLink::query()->where('financial_transaction_id', $expenseTx->id)->exists())->toBeTrue()
        ->and(RecurringPaymentLink::query()->where('occurrence_id', $occurrence->id)->count())->toBe(2);

    expect(fn () => $link->fresh()?->update(['currency_code' => 'USD']))
        ->toThrow(ImmutableRecordException::class);
});
