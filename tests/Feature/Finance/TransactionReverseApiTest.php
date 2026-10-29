<?php

use App\DataTransferObjects\CreateFinancialTransactionInput;
use App\Enums\CategoryType;
use App\Enums\FinancialTransactionType;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\JournalEntry;
use App\Models\JournalPosting;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\AccountBalanceService;
use App\Services\AuditService;
use App\Services\CategoryService;
use App\Services\FinancialAccountService;
use App\Services\FinancialTransactionService;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CurrencySeeder::class);
});

/**
 * @return array{
 *     user: User,
 *     checking: FinancialAccount,
 *     savings: FinancialAccount,
 *     salary: Category,
 *     groceries: Category
 * }
 */
function reverseApiFixture(): array
{
    $user = User::factory()->create();
    $accountService = app(FinancialAccountService::class);
    $categoryService = app(CategoryService::class);

    $checking = $accountService->createAccount($user, [
        'name' => 'Checking PHP',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);
    $savings = $accountService->createAccount($user, [
        'name' => 'Savings PHP',
        'type' => 'savings',
        'currency_code' => 'PHP',
    ]);
    $salary = $categoryService->createCategory($user, [
        'name' => 'Salary',
        'type' => CategoryType::Income,
    ]);
    $groceries = $categoryService->createCategory($user, [
        'name' => 'Groceries',
        'type' => CategoryType::Expense,
    ]);

    return compact('user', 'checking', 'savings', 'salary', 'groceries');
}

test('guests cannot reverse transactions', function (): void {
    $fixture = reverseApiFixture();

    $created = app(FinancialTransactionService::class)->createTransaction(new CreateFinancialTransactionInput(
        user: $fixture['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: $fixture['checking']->id,
        categoryId: $fixture['groceries']->id,
        amount: '100.00',
        entryDate: '2026-10-27',
        description: 'Guest reverse target',
        idempotencyKey: 'c1000000-0000-4000-8000-000000000001',
    ));

    $transaction = $created->transaction;

    $this->post(route('transactions.reverse', $transaction), [
        'idempotency_key' => 'c1000000-0000-4000-8000-000000000002',
        'reason' => 'Should require auth',
    ])->assertRedirect(route('login'));

    $this->postJson(route('transactions.reverse', $transaction), [
        'idempotency_key' => 'c1000000-0000-4000-8000-000000000002',
        'reason' => 'Should require auth',
    ])->assertUnauthorized();

    expect(FinancialTransaction::query()->count())->toBe(1)
        ->and(JournalEntry::query()->count())->toBe(1);
});

test('authenticated users can reverse income, expense, and transfer transactions with exact opposite postings and restored balances', function (): void {
    $fixture = reverseApiFixture();
    $user = $fixture['user'];
    $balanceService = app(AccountBalanceService::class);

    $income = $this->actingAs($user)->postJson(route('transactions.store'), [
        'type' => 'income',
        'financial_account_id' => $fixture['checking']->id,
        'category_id' => $fixture['salary']->id,
        'amount' => '40000.00',
        'entry_date' => '2026-10-25',
        'description' => 'October Payroll',
        'idempotency_key' => 'c2000000-0000-4000-8000-000000000001',
    ])->assertCreated();

    $incomeId = (int) $income->json('data.id');
    $salaryLedger = LedgerAccount::query()
        ->where('category_id', $fixture['salary']->id)
        ->where('currency_code', 'PHP')
        ->firstOrFail();

    expect($balanceService->getBalanceForLedgerAccount($fixture['checking']->ledgerAccount)->amountMinor)->toBe(4000000);

    $incomeReverse = $this->actingAs($user)->postJson(route('transactions.reverse', $incomeId), [
        'idempotency_key' => 'c2000000-0000-4000-8000-000000000002',
        'reason' => 'Wrong entry date',
    ]);

    $incomeReverse->assertCreated()
        ->assertJsonPath('meta.was_recently_created', true)
        ->assertJsonPath('meta.idempotent_replay', false)
        ->assertJsonPath('data.original.id', $incomeId)
        ->assertJsonPath('data.original.is_reversed', true)
        ->assertJsonPath('data.original.is_reversal', false)
        ->assertJsonPath('data.reversal.is_reversal', true)
        ->assertJsonPath('data.reversal.is_reversed', false)
        ->assertJsonPath('data.reversal.reversed_transaction_id', $incomeId)
        ->assertJsonPath('data.reversal.reversal_reason', 'Wrong entry date')
        ->assertJsonPath('data.reversal.description', 'Reversal: October Payroll')
        ->assertJsonPath('data.reversal.amount_minor', 4000000)
        ->assertJsonPath('data.original.reversal_transaction_id', (int) $incomeReverse->json('data.reversal.id'));

    $incomeReversalId = (int) $incomeReverse->json('data.reversal.id');
    $incomeReversal = FinancialTransaction::with('journalEntry.postings')->findOrFail($incomeReversalId);
    $incomeRevPostings = $incomeReversal->journalEntry->postings->keyBy('ledger_account_id');

    expect($incomeRevPostings)->toHaveCount(2)
        ->and((int) $incomeRevPostings[$fixture['checking']->ledgerAccount->id]->debit_amount_minor)->toBe(0)
        ->and((int) $incomeRevPostings[$fixture['checking']->ledgerAccount->id]->credit_amount_minor)->toBe(4000000)
        ->and((int) $incomeRevPostings[$salaryLedger->id]->debit_amount_minor)->toBe(4000000)
        ->and((int) $incomeRevPostings[$salaryLedger->id]->credit_amount_minor)->toBe(0)
        ->and($balanceService->getBalanceForLedgerAccount($fixture['checking']->ledgerAccount)->amountMinor)->toBe(0)
        ->and($balanceService->getBalanceForLedgerAccount($salaryLedger)->amountMinor)->toBe(0);

    $expense = $this->actingAs($user)->postJson(route('transactions.store'), [
        'type' => 'expense',
        'financial_account_id' => $fixture['checking']->id,
        'category_id' => $fixture['groceries']->id,
        'amount' => '3500.75',
        'entry_date' => '2026-10-26',
        'description' => 'Supermarket haul',
        'idempotency_key' => 'c2000000-0000-4000-8000-000000000003',
    ])->assertCreated();

    $expenseId = (int) $expense->json('data.id');
    $groceriesLedger = LedgerAccount::query()
        ->where('category_id', $fixture['groceries']->id)
        ->where('currency_code', 'PHP')
        ->firstOrFail();

    $expenseReverse = $this->actingAs($user)->postJson(route('transactions.reverse', $expenseId), [
        'idempotency_key' => 'c2000000-0000-4000-8000-000000000004',
        'reason' => 'Duplicate expense',
    ])->assertCreated();

    expect((int) $expenseReverse->json('data.reversal.reversed_transaction_id'))->toBe($expenseId)
        ->and($balanceService->getBalanceForLedgerAccount($fixture['checking']->ledgerAccount)->amountMinor)->toBe(0)
        ->and($balanceService->getBalanceForLedgerAccount($groceriesLedger)->amountMinor)->toBe(0);

    $transfer = $this->actingAs($user)->postJson(route('transactions.store'), [
        'type' => 'transfer',
        'financial_account_id' => $fixture['checking']->id,
        'transfer_financial_account_id' => $fixture['savings']->id,
        'amount' => '12000.00',
        'entry_date' => '2026-10-27',
        'description' => 'Move to savings',
        'idempotency_key' => 'c2000000-0000-4000-8000-000000000005',
    ])->assertCreated();

    $transferId = (int) $transfer->json('data.id');

    expect($balanceService->getBalanceForLedgerAccount($fixture['checking']->ledgerAccount)->amountMinor)->toBe(-1200000)
        ->and($balanceService->getBalanceForLedgerAccount($fixture['savings']->ledgerAccount)->amountMinor)->toBe(1200000);

    $transferReverse = $this->actingAs($user)->postJson(route('transactions.reverse', $transferId), [
        'idempotency_key' => 'c2000000-0000-4000-8000-000000000006',
        'reason' => 'Transfer not needed',
    ])->assertCreated();

    $transferReversal = FinancialTransaction::with('journalEntry.postings')
        ->findOrFail((int) $transferReverse->json('data.reversal.id'));
    $transferRevPostings = $transferReversal->journalEntry->postings->keyBy('ledger_account_id');

    expect($transferRevPostings)->toHaveCount(2)
        ->and((int) $transferRevPostings[$fixture['checking']->ledgerAccount->id]->debit_amount_minor)->toBe(1200000)
        ->and((int) $transferRevPostings[$fixture['checking']->ledgerAccount->id]->credit_amount_minor)->toBe(0)
        ->and((int) $transferRevPostings[$fixture['savings']->ledgerAccount->id]->debit_amount_minor)->toBe(0)
        ->and((int) $transferRevPostings[$fixture['savings']->ledgerAccount->id]->credit_amount_minor)->toBe(1200000)
        ->and($balanceService->getBalanceForLedgerAccount($fixture['checking']->ledgerAccount)->amountMinor)->toBe(0)
        ->and($balanceService->getBalanceForLedgerAccount($fixture['savings']->ledgerAccount)->amountMinor)->toBe(0);

    expect(AuditEvent::query()
        ->where('action', AuditService::ACTION_TRANSACTION_REVERSED)
        ->count())->toBe(3);
});

test('transaction reverse API enforces tenant isolation, validation, idempotency, and conflict rules without partial writes', function (): void {
    $fixture = reverseApiFixture();
    $userA = $fixture['user'];
    $userB = User::factory()->create();

    $created = $this->actingAs($userA)->postJson(route('transactions.store'), [
        'type' => 'expense',
        'financial_account_id' => $fixture['checking']->id,
        'category_id' => $fixture['groceries']->id,
        'amount' => '250.50',
        'entry_date' => '2026-10-27',
        'description' => 'Coffee run',
        'idempotency_key' => 'c3000000-0000-4000-8000-000000000001',
    ])->assertCreated();

    $transactionId = (int) $created->json('data.id');
    $transaction = FinancialTransaction::findOrFail($transactionId);
    $baselineTxCount = FinancialTransaction::query()->count();
    $baselineJeCount = JournalEntry::query()->count();
    $baselineJpCount = JournalPosting::query()->count();

    // Cross-tenant isolation: user B receives 403 and creates no records
    $this->actingAs($userB)->postJson(route('transactions.reverse', $transaction), [
        'idempotency_key' => 'c3000000-0000-4000-8000-000000000099',
        'reason' => 'Hijack attempt',
    ])->assertForbidden();

    expect(FinancialTransaction::query()->count())->toBe($baselineTxCount)
        ->and(JournalEntry::query()->count())->toBe($baselineJeCount)
        ->and(JournalPosting::query()->count())->toBe($baselineJpCount);

    // Missing / empty / oversized reason
    $this->actingAs($userA)->postJson(route('transactions.reverse', $transaction), [
        'idempotency_key' => 'c3000000-0000-4000-8000-000000000010',
    ])->assertUnprocessable()->assertJsonValidationErrors('reason');

    $this->actingAs($userA)->postJson(route('transactions.reverse', $transaction), [
        'idempotency_key' => 'c3000000-0000-4000-8000-000000000011',
        'reason' => '   ',
    ])->assertUnprocessable()->assertJsonValidationErrors('reason');

    $this->actingAs($userA)->postJson(route('transactions.reverse', $transaction), [
        'idempotency_key' => 'c3000000-0000-4000-8000-000000000012',
        'reason' => str_repeat('x', 256),
    ])->assertUnprocessable()->assertJsonValidationErrors('reason');

    // Invalid idempotency key
    $this->actingAs($userA)->postJson(route('transactions.reverse', $transaction), [
        'idempotency_key' => 'not-a-uuid',
        'reason' => 'Invalid key',
    ])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

    expect(FinancialTransaction::query()->count())->toBe($baselineTxCount)
        ->and(JournalEntry::query()->count())->toBe($baselineJeCount)
        ->and(JournalPosting::query()->count())->toBe($baselineJpCount);

    $payload = [
        'idempotency_key' => 'c3000000-0000-4000-8000-000000000020',
        'reason' => 'Correcting duplicate entry',
    ];

    $first = $this->actingAs($userA)->postJson(route('transactions.reverse', $transaction), $payload);
    $first->assertCreated()
        ->assertJsonPath('meta.was_recently_created', true)
        ->assertJsonPath('data.reversal.reversed_transaction_id', $transactionId);

    $reversalId = (int) $first->json('data.reversal.id');
    $afterSuccessTxCount = FinancialTransaction::query()->count();
    $afterSuccessJeCount = JournalEntry::query()->count();
    $afterSuccessJpCount = JournalPosting::query()->count();

    // Same-key retry returns existing reversal
    $replay = $this->actingAs($userA)->postJson(route('transactions.reverse', $transaction), $payload);
    $replay->assertOk()
        ->assertJsonPath('meta.was_recently_created', false)
        ->assertJsonPath('meta.idempotent_replay', true)
        ->assertJsonPath('data.reversal.id', $reversalId)
        ->assertJsonPath('data.original.id', $transactionId);

    expect(FinancialTransaction::query()->count())->toBe($afterSuccessTxCount)
        ->and(JournalEntry::query()->count())->toBe($afterSuccessJeCount)
        ->and(JournalPosting::query()->count())->toBe($afterSuccessJpCount);

    // Same key, different payload → 409, no partial writes
    $this->actingAs($userA)->postJson(route('transactions.reverse', $transaction), [
        'idempotency_key' => $payload['idempotency_key'],
        'reason' => 'Different reason for same key',
    ])->assertStatus(409)
        ->assertJsonValidationErrors('idempotency_key');

    expect(FinancialTransaction::query()->count())->toBe($afterSuccessTxCount)
        ->and(JournalEntry::query()->count())->toBe($afterSuccessJeCount)
        ->and(JournalPosting::query()->count())->toBe($afterSuccessJpCount);

    // Already reversed with a different key → 409, no partial writes
    $this->actingAs($userA)->postJson(route('transactions.reverse', $transaction), [
        'idempotency_key' => 'c3000000-0000-4000-8000-000000000030',
        'reason' => 'Second attempt with new key',
    ])->assertStatus(409)
        ->assertJsonValidationErrors('transaction');

    expect(FinancialTransaction::query()->count())->toBe($afterSuccessTxCount)
        ->and(JournalEntry::query()->count())->toBe($afterSuccessJeCount)
        ->and(JournalPosting::query()->count())->toBe($afterSuccessJpCount);

    // Reversal-of-reversal rejected
    $this->actingAs($userA)->postJson(route('transactions.reverse', $reversalId), [
        'idempotency_key' => 'c3000000-0000-4000-8000-000000000040',
        'reason' => 'Trying to reverse a reversal',
    ])->assertStatus(409)
        ->assertJsonValidationErrors('transaction');

    expect(FinancialTransaction::query()->count())->toBe($afterSuccessTxCount)
        ->and(JournalEntry::query()->count())->toBe($afterSuccessJeCount)
        ->and(JournalPosting::query()->count())->toBe($afterSuccessJpCount);

    // Unknown transaction → 404
    $this->actingAs($userA)->postJson(route('transactions.reverse', 999999), [
        'idempotency_key' => 'c3000000-0000-4000-8000-000000000050',
        'reason' => 'Missing transaction',
    ])->assertNotFound();
});
