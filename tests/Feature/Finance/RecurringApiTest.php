<?php

use App\DataTransferObjects\CreateBudgetInput;
use App\DataTransferObjects\CreateFinancialTransactionInput;
use App\DataTransferObjects\ReverseFinancialTransactionInput;
use App\DataTransferObjects\SetBudgetAllocationInput;
use App\Enums\CategoryType;
use App\Enums\FinancialTransactionType;
use App\Enums\RecurringOccurrenceDisplayStatus;
use App\Enums\RecurringOccurrenceStatus;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\JournalEntry;
use App\Models\RecurringObligation;
use App\Models\RecurringOccurrence;
use App\Models\RecurringPaymentLink;
use App\Models\User;
use App\Services\BudgetService;
use App\Services\CategoryService;
use App\Services\FinancialAccountService;
use App\Services\FinancialTransactionService;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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
 *     rent: Category,
 *     dining: Category,
 *     salary: Category,
 *     checking: FinancialAccount,
 *     usdAccount: FinancialAccount,
 *     otherExpense: Category,
 *     otherChecking: FinancialAccount
 * }
 */
function recurringApiFixtures(): array
{
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $categoryService = app(CategoryService::class);
    $accountService = app(FinancialAccountService::class);

    $rent = $categoryService->createCategory($user, ['name' => 'Rent', 'type' => CategoryType::Expense]);
    $dining = $categoryService->createCategory($user, ['name' => 'Dining', 'type' => CategoryType::Expense]);
    $salary = $categoryService->createCategory($user, ['name' => 'Salary', 'type' => CategoryType::Income]);
    $checking = $accountService->createAccount($user, [
        'name' => 'Checking',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);
    $usdAccount = $accountService->createAccount($user, [
        'name' => 'USD Checking',
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);
    $otherExpense = $categoryService->createCategory($otherUser, [
        'name' => 'Other Rent',
        'type' => CategoryType::Expense,
    ]);
    $otherChecking = $accountService->createAccount($otherUser, [
        'name' => 'Other Checking',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);

    return compact(
        'user',
        'otherUser',
        'rent',
        'dining',
        'salary',
        'checking',
        'usdAccount',
        'otherExpense',
        'otherChecking',
    );
}

function obligationPayload(array $fixtures, array $overrides = []): array
{
    return array_merge([
        'idempotency_key' => (string) Str::uuid(),
        'name' => 'Apartment Rent',
        'description' => 'Monthly rent',
        'amount' => '5000.00',
        'currency_code' => 'PHP',
        'category_id' => $fixtures['rent']->id,
        'financial_account_id' => $fixtures['checking']->id,
        'start_date' => '2026-10-31',
        'frequency' => 'monthly',
        'anchor_day' => 31,
        'ends_of_month' => false,
    ], $overrides);
}

test('guests cannot access recurring endpoints', function (): void {
    $fixtures = recurringApiFixtures();
    $obligation = RecurringObligation::query()->create([
        'user_id' => $fixtures['user']->id,
        'idempotency_key' => (string) Str::uuid(),
        'name' => 'Temp',
        'description' => null,
        'currency_code' => 'PHP',
        'amount_minor' => 1000,
        'category_id' => $fixtures['rent']->id,
        'category_type' => CategoryType::Expense,
        'financial_account_id' => null,
        'start_date' => '2026-10-31',
        'frequency' => 'monthly',
        'interval_count' => 1,
        'anchor_day' => 31,
        'ends_of_month' => false,
        'next_occurrence_date' => null,
        'status' => 'active',
        'paused_at' => null,
        'cancelled_at' => null,
    ]);

    $this->getJson(route('recurring-obligations.index'))->assertUnauthorized();
    $this->postJson(route('recurring-obligations.store'), [])->assertUnauthorized();
    $this->getJson(route('recurring-obligations.show', $obligation))->assertUnauthorized();
    $this->getJson(route('recurring-occurrences.index'))->assertUnauthorized();
});

test('authenticated users can create list filter and paginate obligations with tenant isolation', function (): void {
    $fixtures = recurringApiFixtures();

    $created = $this->actingAs($fixtures['user'])->postJson(
        route('recurring-obligations.store'),
        obligationPayload($fixtures, ['idempotency_key' => '11111111-1111-4111-8111-111111111111']),
    );
    $created->assertCreated()
        ->assertJsonPath('meta.was_recently_created', true)
        ->assertJsonPath('data.name', 'Apartment Rent')
        ->assertJsonPath('data.amount_minor', 500_000)
        ->assertJsonPath('data.currency_code', 'PHP')
        ->assertJsonPath('data.status', 'active');

    $this->actingAs($fixtures['user'])->postJson(
        route('recurring-obligations.store'),
        obligationPayload($fixtures, [
            'name' => 'USD SaaS',
            'amount' => '20.00',
            'currency_code' => 'USD',
            'financial_account_id' => $fixtures['usdAccount']->id,
            'start_date' => '2026-10-15',
            'anchor_day' => 15,
        ]),
    )->assertCreated();

    $this->actingAs($fixtures['otherUser'])->postJson(
        route('recurring-obligations.store'),
        obligationPayload($fixtures, [
            'category_id' => $fixtures['otherExpense']->id,
            'financial_account_id' => $fixtures['otherChecking']->id,
        ]),
    )->assertCreated();

    $list = $this->actingAs($fixtures['user'])->getJson(route('recurring-obligations.index'));
    $list->assertOk()->assertJsonPath('meta.total', 2);
    $ids = collect($list->json('data'))->pluck('id');
    expect($ids)->not->toContain(
        RecurringObligation::query()->where('user_id', $fixtures['otherUser']->id)->value('id'),
    );

    $filtered = $this->actingAs($fixtures['user'])->getJson(route('recurring-obligations.index', [
        'currency_code' => 'USD',
        'status' => 'active',
    ]));
    $filtered->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('filters.currency_code', 'USD')
        ->assertJsonPath('data.0.currency_code', 'USD');

    $page = $this->actingAs($fixtures['user'])->getJson(route('recurring-obligations.index', [
        'per_page' => 1,
        'page' => 2,
    ]));
    $page->assertOk()
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonCount(1, 'data');
});

test('obligation create supports uuid replay and payload conflict', function (): void {
    $fixtures = recurringApiFixtures();
    $uuid = '22222222-2222-4222-8222-222222222222';
    $payload = obligationPayload($fixtures, ['idempotency_key' => $uuid]);

    $first = $this->actingAs($fixtures['user'])->postJson(route('recurring-obligations.store'), $payload);
    $first->assertCreated();

    $replay = $this->actingAs($fixtures['user'])->postJson(route('recurring-obligations.store'), $payload);
    $replay->assertOk()
        ->assertJsonPath('meta.was_recently_created', false)
        ->assertJsonPath('meta.idempotent_replay', true)
        ->assertJsonPath('data.id', $first->json('data.id'));

    $this->actingAs($fixtures['user'])->postJson(
        route('recurring-obligations.store'),
        obligationPayload($fixtures, [
            'idempotency_key' => $uuid,
            'amount' => '6000.00',
        ]),
    )->assertStatus(409)
        ->assertJsonPath('errors.idempotency_key.0', fn ($message) => str_contains((string) $message, 'idempotency'));

    expect(RecurringObligation::query()->where('user_id', $fixtures['user']->id)->count())->toBe(1);
});

test('obligation create rejects invalid money frequency dates and ownership mismatches', function (): void {
    $fixtures = recurringApiFixtures();

    $this->actingAs($fixtures['user'])->postJson(
        route('recurring-obligations.store'),
        obligationPayload($fixtures, ['amount' => '12.345']),
    )->assertUnprocessable()->assertJsonValidationErrors('amount');

    $this->actingAs($fixtures['user'])->postJson(
        route('recurring-obligations.store'),
        obligationPayload($fixtures, ['frequency' => 'daily']),
    )->assertUnprocessable()->assertJsonValidationErrors('frequency');

    $this->actingAs($fixtures['user'])->postJson(
        route('recurring-obligations.store'),
        obligationPayload($fixtures, ['start_date' => '31-10-2026']),
    )->assertUnprocessable()->assertJsonValidationErrors('start_date');

    $this->actingAs($fixtures['user'])->postJson(
        route('recurring-obligations.store'),
        obligationPayload($fixtures, ['category_id' => $fixtures['otherExpense']->id]),
    )->assertUnprocessable()->assertJsonValidationErrors('category_id');

    $this->actingAs($fixtures['user'])->postJson(
        route('recurring-obligations.store'),
        obligationPayload($fixtures, ['financial_account_id' => $fixtures['otherChecking']->id]),
    )->assertUnprocessable()->assertJsonValidationErrors('financial_account_id');

    $this->actingAs($fixtures['user'])->postJson(
        route('recurring-obligations.store'),
        obligationPayload($fixtures, ['financial_account_id' => $fixtures['usdAccount']->id]),
    )->assertUnprocessable()->assertJsonValidationErrors('financial_account_id');

    $this->actingAs($fixtures['user'])->postJson(
        route('recurring-obligations.store'),
        obligationPayload($fixtures, ['category_id' => $fixtures['salary']->id]),
    )->assertUnprocessable()->assertJsonValidationErrors('category_id');
});

test('obligation update pause resume cancel and terminal cancelled behavior', function (): void {
    $fixtures = recurringApiFixtures();
    $id = (int) $this->actingAs($fixtures['user'])->postJson(
        route('recurring-obligations.store'),
        obligationPayload($fixtures),
    )->json('data.id');

    $this->actingAs($fixtures['user'])->patchJson(route('recurring-obligations.update', $id), [
        'name' => 'Updated Rent',
        'description' => 'New description',
        'amount' => '5500.00',
        'category_id' => $fixtures['rent']->id,
        'financial_account_id' => $fixtures['checking']->id,
        'start_date' => '2026-10-31',
        'frequency' => 'monthly',
        'anchor_day' => 31,
        'ends_of_month' => false,
    ])->assertOk()
        ->assertJsonPath('data.name', 'Updated Rent')
        ->assertJsonPath('data.amount_minor', 550_000);

    $this->actingAs($fixtures['user'])->postJson(route('recurring-obligations.pause', $id))
        ->assertOk()
        ->assertJsonPath('data.status', 'paused');

    $this->actingAs($fixtures['user'])->postJson(route('recurring-obligations.resume', $id))
        ->assertOk()
        ->assertJsonPath('data.status', 'active');

    $this->actingAs($fixtures['user'])->postJson(route('recurring-obligations.cancel', $id))
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    $this->actingAs($fixtures['user'])->postJson(route('recurring-obligations.resume', $id))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('obligation');

    $this->actingAs($fixtures['user'])->patchJson(route('recurring-obligations.update', $id), [
        'name' => 'Nope',
        'amount' => '5500.00',
        'category_id' => $fixtures['rent']->id,
        'start_date' => '2026-10-31',
        'frequency' => 'monthly',
        'anchor_day' => 31,
    ])->assertUnprocessable()->assertJsonValidationErrors('obligation');

    $foreignId = (int) $this->actingAs($fixtures['otherUser'])->postJson(
        route('recurring-obligations.store'),
        obligationPayload($fixtures, [
            'category_id' => $fixtures['otherExpense']->id,
            'financial_account_id' => $fixtures['otherChecking']->id,
        ]),
    )->json('data.id');

    $this->actingAs($fixtures['user'])->getJson(route('recurring-obligations.show', $foreignId))
        ->assertForbidden();
    $this->actingAs($fixtures['user'])->postJson(route('recurring-obligations.pause', $foreignId))
        ->assertForbidden();
});

test('occurrence listing filters detail skip and derived payment fields', function (): void {
    $fixtures = recurringApiFixtures();
    $obligationId = (int) $this->actingAs($fixtures['user'])->postJson(
        route('recurring-obligations.store'),
        obligationPayload($fixtures),
    )->json('data.id');

    $list = $this->actingAs($fixtures['user'])->getJson(route('recurring-occurrences.index', [
        'obligation_id' => $obligationId,
        'from_date' => '2026-10-01',
        'to_date' => '2026-12-31',
        'currency_code' => 'PHP',
    ]));
    $list->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('data.0.due_date', '2026-10-31')
        ->assertJsonPath('data.0.display_status', RecurringOccurrenceDisplayStatus::Upcoming->value)
        ->assertJsonPath('data.0.expected_amount_minor', 500_000)
        ->assertJsonPath('data.0.paid_amount_minor', 0)
        ->assertJsonPath('data.0.remaining_amount_minor', 500_000)
        ->assertJsonPath('data.0.is_paid', false)
        ->assertJsonPath('data.0.obligation_name', 'Apartment Rent');

    $occurrenceId = (int) $list->json('data.0.id');

    $this->actingAs($fixtures['user'])->getJson(route('recurring-occurrences.show', $occurrenceId))
        ->assertOk()
        ->assertJsonPath('data.id', $occurrenceId)
        ->assertJsonPath('data.obligation_id', $obligationId);

    Carbon::setTestNow(Carbon::parse('2026-11-05 12:00:00', 'UTC'));
    $this->actingAs($fixtures['user'])->getJson(route('recurring-occurrences.show', $occurrenceId))
        ->assertOk()
        ->assertJsonPath('data.display_status', RecurringOccurrenceDisplayStatus::Overdue->value)
        ->assertJsonPath('data.is_overdue', true);

    Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00', 'UTC'));
    $novId = (int) collect($list->json('data'))->firstWhere('due_date', '2026-11-30')['id'];
    $this->actingAs($fixtures['user'])->postJson(route('recurring-occurrences.skip', $novId))
        ->assertOk()
        ->assertJsonPath('data.status', RecurringOccurrenceStatus::Skipped->value)
        ->assertJsonPath('data.display_status', RecurringOccurrenceDisplayStatus::Skipped->value);

    $this->actingAs($fixtures['otherUser'])->getJson(route('recurring-occurrences.show', $occurrenceId))
        ->assertForbidden();
    $this->actingAs($fixtures['otherUser'])->postJson(route('recurring-occurrences.skip', $occurrenceId))
        ->assertForbidden();
});

test('payment link replay conflict unlink partial payments and reversal-aware totals', function (): void {
    $fixtures = recurringApiFixtures();
    $obligationId = (int) $this->actingAs($fixtures['user'])->postJson(
        route('recurring-obligations.store'),
        obligationPayload($fixtures),
    )->json('data.id');

    $occurrenceId = (int) RecurringOccurrence::query()
        ->where('obligation_id', $obligationId)
        ->whereDate('due_date', '2026-10-31')
        ->value('id');

    $txnService = app(FinancialTransactionService::class);
    $a = $txnService->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: $fixtures['checking']->id,
        amount: '2000.00',
        entryDate: '2026-10-15',
        description: 'Partial rent',
        idempotencyKey: (string) Str::uuid(),
        categoryId: $fixtures['rent']->id,
    ))->transaction;
    $b = $txnService->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: $fixtures['checking']->id,
        amount: '3000.00',
        entryDate: '2026-10-16',
        description: 'Rest of rent',
        idempotencyKey: (string) Str::uuid(),
        categoryId: $fixtures['rent']->id,
    ))->transaction;

    $linkA = $this->actingAs($fixtures['user'])->postJson(
        route('recurring-occurrences.payment-links.store', $occurrenceId),
        ['financial_transaction_id' => $a->id],
    );
    $linkA->assertCreated()
        ->assertJsonPath('meta.was_recently_created', true)
        ->assertJsonPath('data.occurrence.paid_amount_minor', 200_000)
        ->assertJsonPath('data.occurrence.display_status', RecurringOccurrenceDisplayStatus::PartiallyPaid->value)
        ->assertJsonPath('data.occurrence.is_partially_paid', true);

    $replay = $this->actingAs($fixtures['user'])->postJson(
        route('recurring-occurrences.payment-links.store', $occurrenceId),
        ['financial_transaction_id' => $a->id],
    );
    $replay->assertOk()
        ->assertJsonPath('meta.idempotent_replay', true)
        ->assertJsonPath('data.payment_link.id', $linkA->json('data.payment_link.id'));

    $otherOccurrenceId = (int) RecurringOccurrence::query()
        ->where('obligation_id', $obligationId)
        ->whereDate('due_date', '2026-11-30')
        ->value('id');

    $this->actingAs($fixtures['user'])->postJson(
        route('recurring-occurrences.payment-links.store', $otherOccurrenceId),
        ['financial_transaction_id' => $a->id],
    )->assertStatus(409);

    $this->actingAs($fixtures['user'])->postJson(
        route('recurring-occurrences.payment-links.store', $occurrenceId),
        ['financial_transaction_id' => $b->id],
    )->assertCreated()
        ->assertJsonPath('data.occurrence.is_paid', true)
        ->assertJsonPath('data.occurrence.paid_amount_minor', 500_000);

    $txnService->reverseTransaction(new ReverseFinancialTransactionInput(
        user: $fixtures['user'],
        originalTransactionId: (int) $b->id,
        idempotencyKey: (string) Str::uuid(),
        reason: 'Correction',
    ));

    $this->actingAs($fixtures['user'])->getJson(route('recurring-occurrences.show', $occurrenceId))
        ->assertOk()
        ->assertJsonPath('data.paid_amount_minor', 200_000)
        ->assertJsonPath('data.remaining_amount_minor', 300_000)
        ->assertJsonPath('data.display_status', RecurringOccurrenceDisplayStatus::PartiallyPaid->value);

    expect(RecurringPaymentLink::query()->where('occurrence_id', $occurrenceId)->count())->toBe(2);

    $linkId = (int) $linkA->json('data.payment_link.id');
    $this->actingAs($fixtures['otherUser'])->deleteJson(
        route('recurring-occurrences.payment-links.destroy', [$occurrenceId, $linkId]),
    )->assertForbidden();

    $this->actingAs($fixtures['user'])->deleteJson(
        route('recurring-occurrences.payment-links.destroy', [$occurrenceId, $linkId]),
    )->assertOk()
        ->assertJsonPath('data.occurrence.paid_amount_minor', 0);

    expect(FinancialTransaction::query()->whereKey($a->id)->exists())->toBeTrue();
    expect(RecurringPaymentLink::query()->whereKey($linkId)->exists())->toBeFalse();

    $this->actingAs($fixtures['user'])->deleteJson(
        route('recurring-occurrences.payment-links.destroy', [$occurrenceId, $linkId]),
    )->assertNotFound();
});

test('schedule and payment-link operations do not mutate ledger or double-count budget actuals', function (): void {
    $fixtures = recurringApiFixtures();
    $budgetService = app(BudgetService::class);
    $txnService = app(FinancialTransactionService::class);

    $budget = $budgetService->createBudget(new CreateBudgetInput(
        user: $fixtures['user'],
        currencyCode: 'PHP',
        year: 2026,
        month: 10,
    ))->budget;
    $budgetService->setAllocation(new SetBudgetAllocationInput(
        user: $fixtures['user'],
        budgetId: (int) $budget->id,
        categoryId: (int) $fixtures['rent']->id,
        amount: '20000.00',
    ));

    $journalsBefore = JournalEntry::query()->count();

    $obligationId = (int) $this->actingAs($fixtures['user'])->postJson(
        route('recurring-obligations.store'),
        obligationPayload($fixtures),
    )->json('data.id');

    expect(JournalEntry::query()->count())->toBe($journalsBefore);
    expect($budgetService->getBudgetSpending($fixtures['user'], (int) $budget->id)->totalActualMinor)->toBe(0);

    $txn = $txnService->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: $fixtures['checking']->id,
        amount: '2000.00',
        entryDate: '2026-10-15',
        description: 'Rent',
        idempotencyKey: (string) Str::uuid(),
        categoryId: $fixtures['rent']->id,
    ))->transaction;

    $afterPost = $budgetService->getBudgetSpending($fixtures['user'], (int) $budget->id)->totalActualMinor;
    expect($afterPost)->toBe(200_000);
    $journalsAfterPost = JournalEntry::query()->count();

    $occurrenceId = (int) RecurringOccurrence::query()
        ->where('obligation_id', $obligationId)
        ->whereDate('due_date', '2026-10-31')
        ->value('id');

    $this->actingAs($fixtures['user'])->postJson(
        route('recurring-occurrences.payment-links.store', $occurrenceId),
        ['financial_transaction_id' => $txn->id],
    )->assertCreated();

    expect(JournalEntry::query()->count())->toBe($journalsAfterPost);
    expect($budgetService->getBudgetSpending($fixtures['user'], (int) $budget->id)->totalActualMinor)->toBe(200_000);

    $linkId = (int) RecurringPaymentLink::query()->where('occurrence_id', $occurrenceId)->value('id');
    $this->actingAs($fixtures['user'])->deleteJson(
        route('recurring-occurrences.payment-links.destroy', [$occurrenceId, $linkId]),
    )->assertOk();

    expect(JournalEntry::query()->count())->toBe($journalsAfterPost);
    expect($budgetService->getBudgetSpending($fixtures['user'], (int) $budget->id)->totalActualMinor)->toBe(200_000);
});
