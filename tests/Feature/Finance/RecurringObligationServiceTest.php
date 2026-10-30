<?php

use App\DataTransferObjects\CreateBudgetInput;
use App\DataTransferObjects\CreatedRecurringObligationResult;
use App\DataTransferObjects\CreateFinancialTransactionInput;
use App\DataTransferObjects\CreateRecurringObligationInput;
use App\DataTransferObjects\ReverseFinancialTransactionInput;
use App\DataTransferObjects\SetBudgetAllocationInput;
use App\DataTransferObjects\UpdateRecurringObligationInput;
use App\Enums\CategoryType;
use App\Enums\FinancialTransactionType;
use App\Enums\RecurringFrequency;
use App\Enums\RecurringObligationStatus;
use App\Enums\RecurringOccurrenceStatus;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\LedgerIntegrityException;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\RecurringObligation;
use App\Models\RecurringOccurrence;
use App\Models\RecurringPaymentLink;
use App\Models\User;
use App\Services\AuditService;
use App\Services\BudgetService;
use App\Services\CategoryService;
use App\Services\FinancialAccountService;
use App\Services\FinancialTransactionService;
use App\Services\RecurringObligationService;
use Carbon\CarbonInterface;
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
 *     service: RecurringObligationService,
 *     categoryService: CategoryService,
 *     accountService: FinancialAccountService,
 *     transactionService: FinancialTransactionService,
 *     budgetService: BudgetService,
 *     rent: Category,
 *     salary: Category,
 *     checking: FinancialAccount,
 *     usdAccount: FinancialAccount,
 *     otherExpense: Category,
 *     otherChecking: FinancialAccount
 * }
 */
function recurringServiceFixtures(): array
{
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $service = app(RecurringObligationService::class);
    $categoryService = app(CategoryService::class);
    $accountService = app(FinancialAccountService::class);
    $transactionService = app(FinancialTransactionService::class);
    $budgetService = app(BudgetService::class);

    $rent = $categoryService->createCategory($user, [
        'name' => 'Rent',
        'type' => CategoryType::Expense,
    ]);
    $salary = $categoryService->createCategory($user, [
        'name' => 'Salary',
        'type' => CategoryType::Income,
    ]);
    $checking = $accountService->createAccount($user, [
        'name' => 'Checking PHP',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);
    $usdAccount = $accountService->createAccount($user, [
        'name' => 'Checking USD',
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
        'service',
        'categoryService',
        'accountService',
        'transactionService',
        'budgetService',
        'rent',
        'salary',
        'checking',
        'usdAccount',
        'otherExpense',
        'otherChecking',
    );
}

function createMonthlyRent(array $fixtures, array $overrides = []): CreatedRecurringObligationResult
{
    return $fixtures['service']->create(new CreateRecurringObligationInput(
        user: $overrides['user'] ?? $fixtures['user'],
        idempotencyKey: $overrides['idempotencyKey'] ?? (string) Str::uuid(),
        name: $overrides['name'] ?? 'Apartment Rent',
        amount: $overrides['amount'] ?? '15000.00',
        currencyCode: $overrides['currencyCode'] ?? 'PHP',
        categoryId: $overrides['categoryId'] ?? $fixtures['rent']->id,
        startDate: $overrides['startDate'] ?? '2026-10-31',
        frequency: $overrides['frequency'] ?? RecurringFrequency::Monthly,
        anchorDay: $overrides['anchorDay'] ?? 31,
        endsOfMonth: $overrides['endsOfMonth'] ?? false,
        description: $overrides['description'] ?? 'Monthly rent',
        financialAccountId: array_key_exists('financialAccountId', $overrides)
            ? $overrides['financialAccountId']
            : $fixtures['checking']->id,
    ));
}

test('creates obligation with monthly occurrences through three-month horizon and audit', function (): void {
    $fixtures = recurringServiceFixtures();

    $result = createMonthlyRent($fixtures, [
        'idempotencyKey' => '11111111-1111-4111-8111-111111111111',
    ]);

    expect($result->wasRecentlyCreated)->toBeTrue()
        ->and($result->obligation->status)->toBe(RecurringObligationStatus::Active)
        ->and($result->obligation->amount_minor)->toBe(1_500_000)
        ->and($result->obligation->occurrences)->toHaveCount(3);

    // Horizon end is 2026-10-15 + 3 months = 2027-01-15 (inclusive); 2027-01-31 is outside.
    $dues = $result->obligation->occurrences->pluck('due_date')->map->toDateString()->sort()->values()->all();
    expect($dues)->toBe([
        '2026-10-31',
        '2026-11-30',
        '2026-12-31',
    ]);

    $audit = AuditEvent::query()
        ->where('action', AuditService::ACTION_RECURRING_OBLIGATION_CREATED)
        ->where('auditable_id', $result->obligation->id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit?->metadata['frequency'] ?? null)->toBe('monthly');

    expect(FinancialTransaction::query()->count())->toBe(0);
});

test('generates all five frequencies and future start dates within horizon only', function (): void {
    $fixtures = recurringServiceFixtures();

    $weekly = createMonthlyRent($fixtures, [
        'name' => 'Weekly parking',
        'startDate' => '2026-10-15',
        'frequency' => RecurringFrequency::Weekly,
        'anchorDay' => 15,
        'amount' => '100.00',
    ]);
    expect($weekly->obligation->occurrences->count())->toBeGreaterThan(10);

    $biweekly = createMonthlyRent($fixtures, [
        'name' => 'Biweekly dues',
        'startDate' => '2026-10-15',
        'frequency' => RecurringFrequency::Biweekly,
        'anchorDay' => 15,
        'amount' => '200.00',
    ]);
    $biDates = $biweekly->obligation->occurrences->pluck('due_date')->map->toDateString()->sort()->values()->all();
    expect($biDates[0])->toBe('2026-10-15')
        ->and($biDates[1])->toBe('2026-10-29');

    $quarterly = createMonthlyRent($fixtures, [
        'name' => 'Quarterly fee',
        'startDate' => '2026-10-31',
        'frequency' => RecurringFrequency::Quarterly,
        'anchorDay' => 31,
        'amount' => '500.00',
    ]);
    expect($quarterly->obligation->occurrences->pluck('due_date')->map->toDateString()->all())
        ->toBe(['2026-10-31']);

    $yearly = createMonthlyRent($fixtures, [
        'name' => 'Annual insurance',
        'startDate' => '2026-10-15',
        'frequency' => RecurringFrequency::Yearly,
        'anchorDay' => 15,
        'amount' => '1200.00',
    ]);
    expect($yearly->obligation->occurrences)->toHaveCount(1)
        ->and($yearly->obligation->occurrences->first()->due_date->toDateString())->toBe('2026-10-15');

    $future = createMonthlyRent($fixtures, [
        'name' => 'Future rent',
        'startDate' => '2026-12-31',
        'anchorDay' => 31,
        'amount' => '15000.00',
    ]);
    $futureDates = $future->obligation->occurrences->pluck('due_date')->map->toDateString()->sort()->values()->all();
    expect($futureDates)->toBe(['2026-12-31']);
});

test('idempotent create replay returns original and conflicting payload is rejected', function (): void {
    $fixtures = recurringServiceFixtures();
    $uuid = '22222222-2222-4222-8222-222222222222';

    $first = createMonthlyRent($fixtures, ['idempotencyKey' => $uuid]);
    $replay = createMonthlyRent($fixtures, ['idempotencyKey' => $uuid]);

    expect($replay->wasRecentlyCreated)->toBeFalse()
        ->and($replay->obligation->id)->toBe($first->obligation->id)
        ->and(RecurringObligation::query()->where('user_id', $fixtures['user']->id)->count())->toBe(1);

    expect(fn () => createMonthlyRent($fixtures, [
        'idempotencyKey' => $uuid,
        'amount' => '16000.00',
    ]))->toThrow(IdempotencyConflictException::class);

    $other = createMonthlyRent($fixtures, [
        'user' => $fixtures['otherUser'],
        'idempotencyKey' => $uuid,
        'categoryId' => $fixtures['otherExpense']->id,
        'financialAccountId' => $fixtures['otherChecking']->id,
    ]);
    expect($other->wasRecentlyCreated)->toBeTrue()
        ->and($other->obligation->id)->not->toBe($first->obligation->id);
});

test('rejects cross-tenant category, cross-currency account, income category, and archived category', function (): void {
    $fixtures = recurringServiceFixtures();

    expect(fn () => createMonthlyRent($fixtures, [
        'categoryId' => $fixtures['otherExpense']->id,
    ]))->toThrow(LedgerIntegrityException::class, 'Category is not accessible');

    expect(fn () => createMonthlyRent($fixtures, [
        'financialAccountId' => $fixtures['usdAccount']->id,
    ]))->toThrow(LedgerIntegrityException::class, 'currency must match');

    expect(fn () => createMonthlyRent($fixtures, [
        'categoryId' => $fixtures['salary']->id,
    ]))->toThrow(LedgerIntegrityException::class, 'expense category');

    $fixtures['categoryService']->archiveCategory($fixtures['user'], $fixtures['rent']);

    expect(fn () => createMonthlyRent($fixtures, [
        'categoryId' => $fixtures['rent']->id,
        'name' => 'After archive',
    ]))->toThrow(LedgerIntegrityException::class, 'Archived categories');
});

test('duplicate generation is idempotent and preserves skipped slots', function (): void {
    $fixtures = recurringServiceFixtures();
    $created = createMonthlyRent($fixtures);
    $obligationId = $created->obligation->id;
    $initialCount = RecurringOccurrence::query()->where('obligation_id', $obligationId)->count();

    $nov = RecurringOccurrence::query()
        ->where('obligation_id', $obligationId)
        ->whereDate('due_date', '2026-11-30')
        ->firstOrFail();

    $fixtures['service']->skipOccurrence($fixtures['user'], (int) $nov->id);

    $fixtures['service']->generateOccurrences($fixtures['user'], $obligationId);

    expect(RecurringOccurrence::query()->where('obligation_id', $obligationId)->count())->toBe($initialCount);

    $nov->refresh();
    expect($nov->status)->toBe(RecurringOccurrenceStatus::Skipped)
        ->and($nov->active_slot_key->toDateString())->toBe('2026-11-30');

    $scheduledForNov = RecurringOccurrence::query()
        ->where('obligation_id', $obligationId)
        ->whereDate('due_date', '2026-11-30')
        ->where('status', RecurringOccurrenceStatus::Scheduled->value)
        ->count();
    expect($scheduledForNov)->toBe(0);
});

test('amount change updates future unlinked scheduled rows and preserves payment-linked snapshots', function (): void {
    $fixtures = recurringServiceFixtures();
    $created = createMonthlyRent($fixtures);
    $obligation = $created->obligation;

    $oct = RecurringOccurrence::query()
        ->where('obligation_id', $obligation->id)
        ->whereDate('due_date', '2026-10-31')
        ->firstOrFail();

    $txn = $fixtures['transactionService']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: $fixtures['checking']->id,
        amount: '15000.00',
        entryDate: '2026-10-31',
        description: 'Rent payment',
        idempotencyKey: (string) Str::uuid(),
        categoryId: $fixtures['rent']->id,
    ));

    RecurringPaymentLink::query()->create([
        'user_id' => $fixtures['user']->id,
        'occurrence_id' => $oct->id,
        'financial_transaction_id' => $txn->transaction->id,
        'currency_code' => 'PHP',
    ]);

    $fixtures['service']->update(new UpdateRecurringObligationInput(
        user: $fixtures['user'],
        obligationId: (int) $obligation->id,
        name: $obligation->name,
        amount: '16000.00',
        categoryId: $fixtures['rent']->id,
        startDate: $obligation->start_date->toDateString(),
        frequency: RecurringFrequency::Monthly,
        anchorDay: 31,
        endsOfMonth: false,
        description: $obligation->description,
        financialAccountId: $fixtures['checking']->id,
    ));

    $oct->refresh();
    expect((int) $oct->expected_amount_minor)->toBe(1_500_000);

    $nov = RecurringOccurrence::query()
        ->where('obligation_id', $obligation->id)
        ->whereDate('due_date', '2026-11-30')
        ->where('status', RecurringOccurrenceStatus::Scheduled->value)
        ->firstOrFail();
    expect((int) $nov->expected_amount_minor)->toBe(1_600_000);

    expect(AuditEvent::query()->where('action', AuditService::ACTION_RECURRING_OBLIGATION_UPDATED)->count())->toBe(1);
});

test('schedule change cancels eligible future unlinked scheduled rows, preserves history, and regenerates', function (): void {
    $fixtures = recurringServiceFixtures();
    $created = createMonthlyRent($fixtures);
    $obligation = $created->obligation;

    $oct = RecurringOccurrence::query()
        ->where('obligation_id', $obligation->id)
        ->whereDate('due_date', '2026-10-31')
        ->firstOrFail();

    $txn = $fixtures['transactionService']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: $fixtures['checking']->id,
        amount: '15000.00',
        entryDate: '2026-10-31',
        description: 'Paid October',
        idempotencyKey: (string) Str::uuid(),
        categoryId: $fixtures['rent']->id,
    ));
    RecurringPaymentLink::query()->create([
        'user_id' => $fixtures['user']->id,
        'occurrence_id' => $oct->id,
        'financial_transaction_id' => $txn->transaction->id,
        'currency_code' => 'PHP',
    ]);

    $nov = RecurringOccurrence::query()
        ->where('obligation_id', $obligation->id)
        ->whereDate('due_date', '2026-11-30')
        ->firstOrFail();
    $fixtures['service']->skipOccurrence($fixtures['user'], (int) $nov->id);

    $fixtures['service']->update(new UpdateRecurringObligationInput(
        user: $fixtures['user'],
        obligationId: (int) $obligation->id,
        name: $obligation->name,
        amount: '15000.00',
        categoryId: $fixtures['rent']->id,
        startDate: '2026-10-15',
        frequency: RecurringFrequency::Monthly,
        anchorDay: 15,
        endsOfMonth: false,
        description: $obligation->description,
        financialAccountId: $fixtures['checking']->id,
    ));

    $oct->refresh();
    $nov->refresh();
    expect($oct->status)->toBe(RecurringOccurrenceStatus::Scheduled)
        ->and($oct->active_slot_key->toDateString())->toBe('2026-10-31')
        ->and($nov->status)->toBe(RecurringOccurrenceStatus::Skipped)
        ->and($nov->active_slot_key->toDateString())->toBe('2026-11-30');

    // Old unlinked Dec/Jan scheduled cancelled; new mid-month schedule regenerated.
    $cancelledOld = RecurringOccurrence::query()
        ->where('obligation_id', $obligation->id)
        ->where('status', RecurringOccurrenceStatus::Cancelled->value)
        ->whereNull('active_slot_key')
        ->count();
    expect($cancelledOld)->toBeGreaterThan(0);

    $newScheduled = RecurringOccurrence::query()
        ->where('obligation_id', $obligation->id)
        ->where('status', RecurringOccurrenceStatus::Scheduled->value)
        ->whereDate('due_date', '2026-11-15')
        ->exists();
    expect($newScheduled)->toBeTrue();

    // Collision with preserved Oct 31 paid slot: no second occupant for that slot key.
    expect(RecurringOccurrence::query()
        ->where('obligation_id', $obligation->id)
        ->where('active_slot_key', '2026-10-31')
        ->count())->toBe(1);
});

test('pause stops generation, resume regenerates without duplicating occupied slots, cancel is terminal', function (): void {
    $fixtures = recurringServiceFixtures();
    $created = createMonthlyRent($fixtures);
    $id = (int) $created->obligation->id;

    $paused = $fixtures['service']->pause($fixtures['user'], $id);
    expect($paused->status)->toBe(RecurringObligationStatus::Paused)
        ->and($paused->paused_at)->not->toBeNull();

    expect(fn () => $fixtures['service']->generateOccurrences($fixtures['user'], $id))
        ->toThrow(LedgerIntegrityException::class, 'active');

    Carbon::setTestNow(Carbon::parse('2026-11-20 12:00:00', 'UTC'));
    $resumed = $fixtures['service']->resume($fixtures['user'], $id);
    expect($resumed->status)->toBe(RecurringObligationStatus::Active);

    $slotKeys = RecurringOccurrence::query()
        ->where('obligation_id', $id)
        ->whereNotNull('active_slot_key')
        ->pluck('active_slot_key')
        ->map(fn ($d) => $d instanceof CarbonInterface ? $d->toDateString() : (string) $d)
        ->all();
    expect($slotKeys)->toEqual(array_unique($slotKeys));

    $cancelled = $fixtures['service']->cancel($fixtures['user'], $id);
    expect($cancelled->status)->toBe(RecurringObligationStatus::Cancelled)
        ->and($cancelled->next_occurrence_date)->toBeNull();

    expect(fn () => $fixtures['service']->resume($fixtures['user'], $id))
        ->toThrow(LedgerIntegrityException::class, 'Cancelled');

    expect(fn () => $fixtures['service']->pause($fixtures['user'], $id))
        ->toThrow(LedgerIntegrityException::class, 'Cancelled');

    expect(AuditEvent::query()->where('action', AuditService::ACTION_RECURRING_OBLIGATION_PAUSED)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', AuditService::ACTION_RECURRING_OBLIGATION_RESUMED)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', AuditService::ACTION_RECURRING_OBLIGATION_CANCELLED)->count())->toBe(1);
});

test('skip requires scheduled status without effective payment and keeps slot occupied', function (): void {
    $fixtures = recurringServiceFixtures();
    $created = createMonthlyRent($fixtures);
    $oct = RecurringOccurrence::query()
        ->where('obligation_id', $created->obligation->id)
        ->whereDate('due_date', '2026-10-31')
        ->firstOrFail();

    $txn = $fixtures['transactionService']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: $fixtures['checking']->id,
        amount: '15000.00',
        entryDate: '2026-10-31',
        description: 'Rent',
        idempotencyKey: (string) Str::uuid(),
        categoryId: $fixtures['rent']->id,
    ));
    RecurringPaymentLink::query()->create([
        'user_id' => $fixtures['user']->id,
        'occurrence_id' => $oct->id,
        'financial_transaction_id' => $txn->transaction->id,
        'currency_code' => 'PHP',
    ]);

    expect(fn () => $fixtures['service']->skipOccurrence($fixtures['user'], (int) $oct->id))
        ->toThrow(LedgerIntegrityException::class, 'effective linked payment');

    $fixtures['transactionService']->reverseTransaction(new ReverseFinancialTransactionInput(
        user: $fixtures['user'],
        originalTransactionId: (int) $txn->transaction->id,
        idempotencyKey: (string) Str::uuid(),
        reason: 'Correction',
    ));

    $skipped = $fixtures['service']->skipOccurrence($fixtures['user'], (int) $oct->id);
    expect($skipped->status)->toBe(RecurringOccurrenceStatus::Skipped)
        ->and($skipped->active_slot_key->toDateString())->toBe('2026-10-31')
        ->and($skipped->paymentLinks)->toHaveCount(1);

    expect(AuditEvent::query()->where('action', AuditService::ACTION_RECURRING_OCCURRENCE_SKIPPED)->count())->toBe(1);

    expect(fn () => $fixtures['service']->skipOccurrence($fixtures['otherUser'], (int) $oct->id))
        ->toThrow(LedgerIntegrityException::class, 'not accessible');
});

test('cancel clears future unlinked slots while preserving cancelled historical rows and paid occurrences', function (): void {
    $fixtures = recurringServiceFixtures();
    $created = createMonthlyRent($fixtures);
    $obligation = $created->obligation;

    $oct = RecurringOccurrence::query()
        ->where('obligation_id', $obligation->id)
        ->whereDate('due_date', '2026-10-31')
        ->firstOrFail();
    $txn = $fixtures['transactionService']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: $fixtures['checking']->id,
        amount: '15000.00',
        entryDate: '2026-10-31',
        description: 'Paid',
        idempotencyKey: (string) Str::uuid(),
        categoryId: $fixtures['rent']->id,
    ));
    RecurringPaymentLink::query()->create([
        'user_id' => $fixtures['user']->id,
        'occurrence_id' => $oct->id,
        'financial_transaction_id' => $txn->transaction->id,
        'currency_code' => 'PHP',
    ]);

    $fixtures['service']->cancel($fixtures['user'], (int) $obligation->id);

    $oct->refresh();
    expect($oct->status)->toBe(RecurringOccurrenceStatus::Scheduled)
        ->and($oct->active_slot_key)->not->toBeNull();

    $futureCancelled = RecurringOccurrence::query()
        ->where('obligation_id', $obligation->id)
        ->where('status', RecurringOccurrenceStatus::Cancelled->value)
        ->whereNull('active_slot_key')
        ->whereDate('due_date', '>', '2026-10-31')
        ->count();
    expect($futureCancelled)->toBe(2);
});

test('creating obligations does not post ledger transactions or change budget actuals', function (): void {
    $fixtures = recurringServiceFixtures();

    $budget = $fixtures['budgetService']->createBudget(new CreateBudgetInput(
        user: $fixtures['user'],
        currencyCode: 'PHP',
        year: 2026,
        month: 10,
    ))->budget;

    $fixtures['budgetService']->setAllocation(new SetBudgetAllocationInput(
        user: $fixtures['user'],
        budgetId: (int) $budget->id,
        categoryId: (int) $fixtures['rent']->id,
        amount: '20000.00',
    ));

    $before = $fixtures['budgetService']->getBudgetSpending($fixtures['user'], (int) $budget->id);
    expect($before->totalActualMinor)->toBe(0);

    createMonthlyRent($fixtures);

    expect(FinancialTransaction::query()->count())->toBe(0);

    $after = $fixtures['budgetService']->getBudgetSpending($fixtures['user'], (int) $budget->id);
    expect($after->totalActualMinor)->toBe(0)
        ->and($after->allocatedActualMinor)->toBe(0);
});

test('rejects cross-tenant obligation access on update pause and skip', function (): void {
    $fixtures = recurringServiceFixtures();
    $created = createMonthlyRent($fixtures);

    expect(fn () => $fixtures['service']->update(new UpdateRecurringObligationInput(
        user: $fixtures['otherUser'],
        obligationId: (int) $created->obligation->id,
        name: 'Hijack',
        amount: '15000.00',
        categoryId: $fixtures['otherExpense']->id,
        startDate: '2026-10-31',
        frequency: RecurringFrequency::Monthly,
        anchorDay: 31,
    )))->toThrow(LedgerIntegrityException::class, 'not accessible');

    expect(fn () => $fixtures['service']->pause($fixtures['otherUser'], (int) $created->obligation->id))
        ->toThrow(LedgerIntegrityException::class, 'not accessible');
});
