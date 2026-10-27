<?php

use App\Enums\CategoryType;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\FinancialTransaction;
use App\Models\JournalEntry;
use App\Models\JournalPosting;
use App\Models\User;
use App\Services\AccountBalanceService;
use App\Services\AuditService;
use App\Services\CategoryService;
use App\Services\FinancialAccountService;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CurrencySeeder::class);
});

test('guests cannot access transaction or category endpoints', function (): void {
    $user = User::factory()->create();
    $category = app(CategoryService::class)->createCategory($user, [
        'name' => 'Groceries',
        'type' => CategoryType::Expense,
    ]);

    $this->get(route('transactions.index'))->assertRedirect(route('login'));
    $this->post(route('transactions.store'), [])->assertRedirect(route('login'));

    $this->get(route('categories.index'))->assertRedirect(route('login'));
    $this->post(route('categories.store'), [])->assertRedirect(route('login'));
    $this->patch(route('categories.update', $category), [])->assertRedirect(route('login'));
    $this->post(route('categories.archive', $category))->assertRedirect(route('login'));
    $this->post(route('categories.restore', $category))->assertRedirect(route('login'));

    $this->getJson(route('transactions.index'))->assertUnauthorized();
    $this->postJson(route('transactions.store'), [])->assertUnauthorized();
    $this->getJson(route('categories.index'))->assertUnauthorized();
    $this->postJson(route('categories.store'), [])->assertUnauthorized();
    $this->patchJson(route('categories.update', $category), [])->assertUnauthorized();
    $this->postJson(route('categories.archive', $category))->assertUnauthorized();
    $this->postJson(route('categories.restore', $category))->assertUnauthorized();
});

test('authenticated users can list, create, rename, archive, and restore categories with idempotent state transitions and strict ownership isolation', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    // 1. Create income and expense categories
    $createIncomeResponse = $this->actingAs($userA)->postJson(route('categories.store'), [
        'name' => '  Salary  ',
        'type' => 'income',
    ]);

    $createIncomeResponse->assertCreated()
        ->assertJsonPath('data.name', 'Salary')
        ->assertJsonPath('data.type', 'income')
        ->assertJsonPath('data.type_label', 'Income')
        ->assertJsonPath('data.classification', 'income')
        ->assertJsonPath('data.is_archived', false);

    $incomeCategoryId = (int) $createIncomeResponse->json('data.id');

    $createExpenseResponse = $this->actingAs($userA)->postJson(route('categories.store'), [
        'name' => 'Groceries',
        'type' => 'expense',
    ]);

    $createExpenseResponse->assertCreated()
        ->assertJsonPath('data.name', 'Groceries')
        ->assertJsonPath('data.type', 'expense')
        ->assertJsonPath('data.classification', 'expense');

    $expenseCategoryId = (int) $createExpenseResponse->json('data.id');
    $expenseCategory = Category::findOrFail($expenseCategoryId);

    // Duplicate category name for same user and type is rejected
    $this->actingAs($userA)->postJson(route('categories.store'), [
        'name' => 'Salary',
        'type' => 'income',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('name');

    // 2. Rename category
    $this->actingAs($userA)->patchJson(route('categories.update', $expenseCategory), [
        'name' => 'Supermarket & Groceries',
    ])->assertOk()
        ->assertJsonPath('data.name', 'Supermarket & Groceries');

    // Attempt to change category type via update is rejected
    $this->actingAs($userA)->patchJson(route('categories.update', $expenseCategory), [
        'name' => 'Supermarket & Groceries',
        'type' => 'income',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('type');

    // 3. Archive category and verify idempotent re-archive
    $this->actingAs($userA)->postJson(route('categories.archive', $expenseCategory))
        ->assertOk()
        ->assertJsonPath('data.is_archived', true);

    $archiveAuditCount = AuditEvent::query()
        ->where('auditable_type', Category::class)
        ->where('auditable_id', $expenseCategoryId)
        ->where('action', AuditService::ACTION_CATEGORY_ARCHIVED)
        ->count();
    expect($archiveAuditCount)->toBe(1);

    // Second archive call is idempotent and does not emit a duplicate audit event
    $this->actingAs($userA)->postJson(route('categories.archive', $expenseCategory))
        ->assertOk()
        ->assertJsonPath('data.is_archived', true);
    expect(AuditEvent::query()
        ->where('auditable_type', Category::class)
        ->where('auditable_id', $expenseCategoryId)
        ->where('action', AuditService::ACTION_CATEGORY_ARCHIVED)
        ->count())->toBe(1);

    // 4. List categories with type and include_archived filters
    $this->actingAs($userA)->getJson(route('categories.index', ['include_archived' => 0]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $incomeCategoryId);

    $this->actingAs($userA)->getJson(route('categories.index', ['include_archived' => 1]))
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->actingAs($userA)->getJson(route('categories.index', ['type' => 'expense']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $expenseCategoryId);

    // 5. Restore category and verify idempotent re-restore
    $this->actingAs($userA)->postJson(route('categories.restore', $expenseCategory))
        ->assertOk()
        ->assertJsonPath('data.is_archived', false)
        ->assertJsonPath('data.archived_at', null);

    $this->actingAs($userA)->postJson(route('categories.restore', $expenseCategory))
        ->assertOk()
        ->assertJsonPath('data.is_archived', false);

    expect(AuditEvent::query()
        ->where('auditable_type', Category::class)
        ->where('auditable_id', $expenseCategoryId)
        ->where('action', AuditService::ACTION_CATEGORY_RESTORED)
        ->count())->toBe(1);

    // 6. User B cannot view or operate on User A's categories
    $this->actingAs($userB)->getJson(route('categories.index'))
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs($userB)->patchJson(route('categories.update', $expenseCategory), [
        'name' => 'Hijacked Category',
    ])->assertForbidden();

    $this->actingAs($userB)->postJson(route('categories.archive', $expenseCategory))
        ->assertForbidden();

    $this->actingAs($userB)->postJson(route('categories.restore', $expenseCategory))
        ->assertForbidden();
});

test('authenticated users can create income, expense, transfer, credit-card, and JPY transactions with exact money parsing and balanced postings', function (): void {
    $user = User::factory()->create();
    $accountService = app(FinancialAccountService::class);
    $categoryService = app(CategoryService::class);
    $balanceService = app(AccountBalanceService::class);

    $checkingPhp = $accountService->createAccount($user, [
        'name' => 'BPI Checking',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);
    $savingsPhp = $accountService->createAccount($user, [
        'name' => 'BPI Savings',
        'type' => 'savings',
        'currency_code' => 'PHP',
    ]);
    $creditCardPhp = $accountService->createAccount($user, [
        'name' => 'BPI Credit Card',
        'type' => 'credit_card',
        'currency_code' => 'PHP',
    ]);
    $jpyCash = $accountService->createAccount($user, [
        'name' => 'Tokyo Yen Wallet',
        'type' => 'cash',
        'currency_code' => 'JPY',
    ]);

    $salaryCategory = $categoryService->createCategory($user, [
        'name' => 'Salary',
        'type' => CategoryType::Income,
    ]);
    $groceriesCategory = $categoryService->createCategory($user, [
        'name' => 'Groceries',
        'type' => CategoryType::Expense,
    ]);

    // 1. Create Income transaction (₱45,000.50 -> 4500050 minor units)
    $incomeResponse = $this->actingAs($user)->postJson(route('transactions.store'), [
        'type' => 'income',
        'financial_account_id' => $checkingPhp->id,
        'category_id' => $salaryCategory->id,
        'amount' => '45000.50',
        'entry_date' => '2026-10-24',
        'description' => 'October Payroll',
        'notes' => 'Net pay',
        'idempotency_key' => '10000000-0000-4000-8000-000000000001',
    ]);

    $incomeResponse->assertCreated()
        ->assertJsonPath('meta.was_recently_created', true)
        ->assertJsonPath('meta.idempotent_replay', false)
        ->assertJsonPath('data.type', 'income')
        ->assertJsonPath('data.currency_code', 'PHP')
        ->assertJsonPath('data.amount_minor', 4500050)
        ->assertJsonPath('data.amount_formatted', '₱45,000.50')
        ->assertJsonPath('data.financial_account.id', $checkingPhp->id)
        ->assertJsonPath('data.category.id', $salaryCategory->id)
        ->assertJsonMissingPath('data.postings');

    // 2. Create Expense transaction on Checking (₱3,200.25 -> 320025 minor units)
    $expenseResponse = $this->actingAs($user)->postJson(route('transactions.store'), [
        'type' => 'expense',
        'financial_account_id' => $checkingPhp->id,
        'category_id' => $groceriesCategory->id,
        'amount' => '3200.25',
        'entry_date' => '2026-10-25',
        'description' => 'Weekly supermarket',
        'idempotency_key' => '10000000-0000-4000-8000-000000000002',
    ]);

    $expenseResponse->assertCreated()
        ->assertJsonPath('data.type', 'expense')
        ->assertJsonPath('data.amount_minor', 320025)
        ->assertJsonPath('data.amount_formatted', '₱3,200.25');

    // 3. Create Credit Card Expense (₱1,800.00 -> increases liability owed to 180000)
    $ccExpenseResponse = $this->actingAs($user)->postJson(route('transactions.store'), [
        'type' => 'expense',
        'financial_account_id' => $creditCardPhp->id,
        'category_id' => $groceriesCategory->id,
        'amount' => '1800.00',
        'entry_date' => '2026-10-26',
        'description' => 'Grocery delivery on card',
        'idempotency_key' => '10000000-0000-4000-8000-000000000003',
    ]);

    $ccExpenseResponse->assertCreated()
        ->assertJsonPath('data.amount_minor', 180000);

    // 4. Create Transfer from Checking to Credit Card (₱1,000.00 payment)
    $ccPaymentResponse = $this->actingAs($user)->postJson(route('transactions.store'), [
        'type' => 'transfer',
        'financial_account_id' => $checkingPhp->id,
        'transfer_financial_account_id' => $creditCardPhp->id,
        'amount' => '1000.00',
        'entry_date' => '2026-10-27',
        'description' => 'Partial credit card bill payment',
        'idempotency_key' => '10000000-0000-4000-8000-000000000004',
    ]);

    $ccPaymentResponse->assertCreated()
        ->assertJsonPath('data.type', 'transfer')
        ->assertJsonPath('data.financial_account.id', $checkingPhp->id)
        ->assertJsonPath('data.transfer_financial_account.id', $creditCardPhp->id)
        ->assertJsonPath('data.category', null)
        ->assertJsonPath('data.amount_minor', 100000);

    // 5. Create Transfer from Checking to Savings (₱5,000.00)
    $this->actingAs($user)->postJson(route('transactions.store'), [
        'type' => 'transfer',
        'financial_account_id' => $checkingPhp->id,
        'transfer_financial_account_id' => $savingsPhp->id,
        'amount' => '5000.00',
        'entry_date' => '2026-10-27',
        'description' => 'Move to savings',
        'idempotency_key' => '10000000-0000-4000-8000-000000000005',
    ])->assertCreated();

    // 6. Create JPY Expense (¥2,450 -> 2450 minor units, exponent 0)
    $this->actingAs($user)->postJson(route('transactions.store'), [
        'type' => 'expense',
        'financial_account_id' => $jpyCash->id,
        'category_id' => $groceriesCategory->id,
        'amount' => '2450',
        'entry_date' => '2026-10-27',
        'description' => 'Konbini snacks',
        'idempotency_key' => '10000000-0000-4000-8000-000000000006',
    ])->assertCreated()
        ->assertJsonPath('data.currency_code', 'JPY')
        ->assertJsonPath('data.amount_minor', 2450)
        ->assertJsonPath('data.amount_formatted', '¥2,450');

    // Verify all journal entries have balanced debit and credit totals
    $entries = JournalEntry::query()->with('postings')->get();
    expect($entries)->toHaveCount(6);
    foreach ($entries as $entry) {
        expect($entry->postings->sum('debit_amount_minor'))
            ->toBe($entry->postings->sum('credit_amount_minor'))
            ->and($entry->postings->sum('debit_amount_minor'))->toBeGreaterThan(0);
    }

    // Verify derived balances:
    // Checking: 4500050 - 320025 - 100000 - 500000 = 3580025
    // Savings: +500000
    // Credit Card (Liability): 180000 - 100000 = 80000
    // JPY Cash: -2450 (negative asset balance allowed)
    expect($balanceService->getBalanceForLedgerAccount($checkingPhp->ledgerAccount)->amountMinor)->toBe(3580025)
        ->and($balanceService->getBalanceForLedgerAccount($savingsPhp->ledgerAccount)->amountMinor)->toBe(500000)
        ->and($balanceService->getBalanceForLedgerAccount($creditCardPhp->ledgerAccount)->amountMinor)->toBe(80000)
        ->and($balanceService->getBalanceForLedgerAccount($jpyCash->ledgerAccount)->amountMinor)->toBe(-2450);
});

test('transaction creation validates inputs, rejects cross-user resources, archived accounts or categories, mismatched category types, cross-currency transfers, and handles idempotent retries and conflicts', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $accountService = app(FinancialAccountService::class);
    $categoryService = app(CategoryService::class);

    $checkingPhp = $accountService->createAccount($userA, [
        'name' => 'Checking PHP',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);
    $checkingUsd = $accountService->createAccount($userA, [
        'name' => 'Checking USD',
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);
    $archivedAccount = $accountService->createAccount($userA, [
        'name' => 'Archived Account',
        'type' => 'savings',
        'currency_code' => 'PHP',
    ]);
    $accountService->archiveAccount($userA, $archivedAccount);

    $userBAccount = $accountService->createAccount($userB, [
        'name' => 'User B Account',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);

    $incomeCategory = $categoryService->createCategory($userA, [
        'name' => 'Salary',
        'type' => CategoryType::Income,
    ]);
    $expenseCategory = $categoryService->createCategory($userA, [
        'name' => 'Groceries',
        'type' => CategoryType::Expense,
    ]);
    $archivedCategory = $categoryService->createCategory($userA, [
        'name' => 'Archived Expense',
        'type' => CategoryType::Expense,
    ]);
    $categoryService->archiveCategory($userA, $archivedCategory);

    $userBCategory = $categoryService->createCategory($userB, [
        'name' => 'User B Expense',
        'type' => CategoryType::Expense,
    ]);

    // 1. Non-string (float) amount or invalid decimal string rejected
    $this->actingAs($userA)->postJson(route('transactions.store'), [
        'type' => 'expense',
        'financial_account_id' => $checkingPhp->id,
        'category_id' => $expenseCategory->id,
        'amount' => 125.50,
        'entry_date' => '2026-10-27',
        'description' => 'Float amount rejected',
        'idempotency_key' => '20000000-0000-4000-8000-000000000001',
    ])->assertUnprocessable()->assertJsonValidationErrors('amount');

    foreach (['0', '0.00', '-15.00', '12.345', 'abc'] as $idx => $badAmount) {
        $this->actingAs($userA)->postJson(route('transactions.store'), [
            'type' => 'expense',
            'financial_account_id' => $checkingPhp->id,
            'category_id' => $expenseCategory->id,
            'amount' => $badAmount,
            'entry_date' => '2026-10-27',
            'description' => 'Invalid decimal string',
            'idempotency_key' => sprintf('20000000-0000-4000-8000-00000000001%d', $idx),
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    // 2. Missing or invalid UUID rejected
    $this->actingAs($userA)->postJson(route('transactions.store'), [
        'type' => 'expense',
        'financial_account_id' => $checkingPhp->id,
        'category_id' => $expenseCategory->id,
        'amount' => '100.00',
        'entry_date' => '2026-10-27',
        'description' => 'Bad UUID',
        'idempotency_key' => 'not-a-valid-uuid',
    ])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

    // 3. Mismatched category type rejected
    $this->actingAs($userA)->postJson(route('transactions.store'), [
        'type' => 'expense',
        'financial_account_id' => $checkingPhp->id,
        'category_id' => $incomeCategory->id,
        'amount' => '100.00',
        'entry_date' => '2026-10-27',
        'description' => 'Mismatched category type',
        'idempotency_key' => '20000000-0000-4000-8000-000000000020',
    ])->assertUnprocessable()->assertJsonValidationErrors('category_id');

    // 4. Archived account or category rejected
    $this->actingAs($userA)->postJson(route('transactions.store'), [
        'type' => 'expense',
        'financial_account_id' => $archivedAccount->id,
        'category_id' => $expenseCategory->id,
        'amount' => '100.00',
        'entry_date' => '2026-10-27',
        'description' => 'Archived account',
        'idempotency_key' => '20000000-0000-4000-8000-000000000021',
    ])->assertUnprocessable()->assertJsonValidationErrors('financial_account_id');

    $this->actingAs($userA)->postJson(route('transactions.store'), [
        'type' => 'expense',
        'financial_account_id' => $checkingPhp->id,
        'category_id' => $archivedCategory->id,
        'amount' => '100.00',
        'entry_date' => '2026-10-27',
        'description' => 'Archived category',
        'idempotency_key' => '20000000-0000-4000-8000-000000000022',
    ])->assertUnprocessable()->assertJsonValidationErrors('category_id');

    // 5. Cross-currency transfer rejected
    $this->actingAs($userA)->postJson(route('transactions.store'), [
        'type' => 'transfer',
        'financial_account_id' => $checkingPhp->id,
        'transfer_financial_account_id' => $checkingUsd->id,
        'amount' => '100.00',
        'entry_date' => '2026-10-27',
        'description' => 'Cross currency transfer',
        'idempotency_key' => '20000000-0000-4000-8000-000000000023',
    ])->assertUnprocessable()->assertJsonValidationErrors('transfer_financial_account_id');

    // 6. Foreign user account or category rejected without leaking existence
    $this->actingAs($userA)->postJson(route('transactions.store'), [
        'type' => 'expense',
        'financial_account_id' => $userBAccount->id,
        'category_id' => $expenseCategory->id,
        'amount' => '100.00',
        'entry_date' => '2026-10-27',
        'description' => 'User B account',
        'idempotency_key' => '20000000-0000-4000-8000-000000000024',
    ])->assertUnprocessable()->assertJsonValidationErrors('financial_account_id');

    $this->actingAs($userA)->postJson(route('transactions.store'), [
        'type' => 'expense',
        'financial_account_id' => $checkingPhp->id,
        'category_id' => $userBCategory->id,
        'amount' => '100.00',
        'entry_date' => '2026-10-27',
        'description' => 'User B category',
        'idempotency_key' => '20000000-0000-4000-8000-000000000025',
    ])->assertUnprocessable()->assertJsonValidationErrors('category_id');

    // 7. Idempotent retry returns 200 OK with idempotent_replay = true, and conflicting reuse returns 409 Conflict
    $validPayload = [
        'type' => 'expense',
        'financial_account_id' => $checkingPhp->id,
        'category_id' => $expenseCategory->id,
        'amount' => '450.75',
        'entry_date' => '2026-10-27',
        'description' => 'Idempotent grocery trip',
        'notes' => 'Original note',
        'idempotency_key' => '30000000-0000-4000-8000-000000000001',
    ];

    $firstAttempt = $this->actingAs($userA)->postJson(route('transactions.store'), $validPayload);
    $firstAttempt->assertCreated()
        ->assertJsonPath('meta.was_recently_created', true)
        ->assertJsonPath('meta.idempotent_replay', false);

    $createdId = (int) $firstAttempt->json('data.id');

    $replayAttempt = $this->actingAs($userA)->postJson(route('transactions.store'), $validPayload);
    $replayAttempt->assertOk()
        ->assertJsonPath('meta.was_recently_created', false)
        ->assertJsonPath('meta.idempotent_replay', true)
        ->assertJsonPath('data.id', $createdId);

    // Conflicting payload with same idempotency_key returns 409 Conflict and does not modify original record
    $conflictPayload = [
        ...$validPayload,
        'amount' => '999.99',
    ];

    $this->actingAs($userA)->postJson(route('transactions.store'), $conflictPayload)
        ->assertStatus(409)
        ->assertJsonValidationErrors('idempotency_key');

    expect(FinancialTransaction::findOrFail($createdId)->amount_minor)->toBe(45075)
        ->and(FinancialTransaction::query()->count())->toBe(1)
        ->and(JournalEntry::query()->count())->toBe(1)
        ->and(JournalPosting::query()->count())->toBe(2);
});

test('transaction listing enforces tenant isolation, pagination, stable ordering, and filtering by type, account (source or transfer destination), category, and date range', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $accountService = app(FinancialAccountService::class);
    $categoryService = app(CategoryService::class);

    $checking = $accountService->createAccount($userA, [
        'name' => 'Checking PHP',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);
    $savings = $accountService->createAccount($userA, [
        'name' => 'Savings PHP',
        'type' => 'savings',
        'currency_code' => 'PHP',
    ]);

    $salaryCategory = $categoryService->createCategory($userA, [
        'name' => 'Salary',
        'type' => CategoryType::Income,
    ]);
    $groceriesCategory = $categoryService->createCategory($userA, [
        'name' => 'Groceries',
        'type' => CategoryType::Expense,
    ]);

    // User B account, category, and transaction (must never appear in User A's listing)
    $userBChecking = $accountService->createAccount($userB, [
        'name' => 'User B Checking',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);
    $userBCategory = $categoryService->createCategory($userB, [
        'name' => 'User B Salary',
        'type' => CategoryType::Income,
    ]);
    $this->actingAs($userB)->postJson(route('transactions.store'), [
        'type' => 'income',
        'financial_account_id' => $userBChecking->id,
        'category_id' => $userBCategory->id,
        'amount' => '99999.00',
        'entry_date' => '2026-10-27',
        'description' => 'User B Private Income',
        'idempotency_key' => '40000000-0000-4000-8000-000000000099',
    ])->assertCreated();

    // User A transactions:
    // Tx 1: 2026-10-20 Income on Checking
    $tx1 = $this->actingAs($userA)->postJson(route('transactions.store'), [
        'type' => 'income',
        'financial_account_id' => $checking->id,
        'category_id' => $salaryCategory->id,
        'amount' => '30000.00',
        'entry_date' => '2026-10-20',
        'description' => 'First paycheck',
        'idempotency_key' => '40000000-0000-4000-8000-000000000001',
    ])->json('data.id');

    // Tx 2: 2026-10-25 Expense on Checking
    $tx2 = $this->actingAs($userA)->postJson(route('transactions.store'), [
        'type' => 'expense',
        'financial_account_id' => $checking->id,
        'category_id' => $groceriesCategory->id,
        'amount' => '1500.00',
        'entry_date' => '2026-10-25',
        'description' => 'Groceries 1',
        'idempotency_key' => '40000000-0000-4000-8000-000000000002',
    ])->json('data.id');

    // Tx 3: 2026-10-25 Transfer from Checking to Savings (same date as Tx 2, higher ID)
    $tx3 = $this->actingAs($userA)->postJson(route('transactions.store'), [
        'type' => 'transfer',
        'financial_account_id' => $checking->id,
        'transfer_financial_account_id' => $savings->id,
        'amount' => '5000.00',
        'entry_date' => '2026-10-25',
        'description' => 'Savings transfer',
        'idempotency_key' => '40000000-0000-4000-8000-000000000003',
    ])->json('data.id');

    // Tx 4: 2026-10-27 Expense on Savings
    $tx4 = $this->actingAs($userA)->postJson(route('transactions.store'), [
        'type' => 'expense',
        'financial_account_id' => $savings->id,
        'category_id' => $groceriesCategory->id,
        'amount' => '800.00',
        'entry_date' => '2026-10-27',
        'description' => 'Groceries from savings',
        'idempotency_key' => '40000000-0000-4000-8000-000000000004',
    ])->json('data.id');

    // 1. Unfiltered listing: only User A's 4 transactions, ordered by entry_date DESC, id DESC ([tx4, tx3, tx2, tx1])
    $listResponse = $this->actingAs($userA)->getJson(route('transactions.index'));
    $listResponse->assertOk()
        ->assertJsonPath('meta.total', 4)
        ->assertJsonCount(4, 'data');

    $returnedIds = array_column($listResponse->json('data'), 'id');
    expect($returnedIds)->toBe([$tx4, $tx3, $tx2, $tx1]);

    // 2. Pagination (per_page = 2)
    $page1 = $this->actingAs($userA)->getJson(route('transactions.index', ['per_page' => 2, 'page' => 1]));
    $page1->assertOk()
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 4);
    expect(array_column($page1->json('data'), 'id'))->toBe([$tx4, $tx3]);

    $page2 = $this->actingAs($userA)->getJson(route('transactions.index', ['per_page' => 2, 'page' => 2]));
    $page2->assertOk()
        ->assertJsonPath('meta.current_page', 2);
    expect(array_column($page2->json('data'), 'id'))->toBe([$tx2, $tx1]);

    // 3. Filter by transaction type
    $transferFilter = $this->actingAs($userA)->getJson(route('transactions.index', ['type' => 'transfer']));
    $transferFilter->assertOk()->assertJsonCount(1, 'data');
    expect($transferFilter->json('data.0.id'))->toBe($tx3);

    // 4. Filter by financial_account_id: filtering by Savings returns BOTH Tx 4 (Savings is primary source)
    // AND Tx 3 (Savings is transfer_financial_account_id destination)
    $savingsFilter = $this->actingAs($userA)->getJson(route('transactions.index', [
        'financial_account_id' => $savings->id,
    ]));
    $savingsFilter->assertOk()->assertJsonCount(2, 'data');
    expect(array_column($savingsFilter->json('data'), 'id'))->toBe([$tx4, $tx3]);

    // 5. Filter by category_id
    $groceriesFilter = $this->actingAs($userA)->getJson(route('transactions.index', [
        'category_id' => $groceriesCategory->id,
    ]));
    $groceriesFilter->assertOk()->assertJsonCount(2, 'data');
    expect(array_column($groceriesFilter->json('data'), 'id'))->toBe([$tx4, $tx2]);

    // 6. Filter by start_date and end_date
    $dateRangeFilter = $this->actingAs($userA)->getJson(route('transactions.index', [
        'start_date' => '2026-10-25',
        'end_date' => '2026-10-25',
    ]));
    $dateRangeFilter->assertOk()->assertJsonCount(2, 'data');
    expect(array_column($dateRangeFilter->json('data'), 'id'))->toBe([$tx3, $tx2]);

    // 7. Filtering by another user's account or category is rejected by validation
    $this->actingAs($userA)->getJson(route('transactions.index', [
        'financial_account_id' => $userBChecking->id,
    ]))->assertUnprocessable()->assertJsonValidationErrors('financial_account_id');

    $this->actingAs($userA)->getJson(route('transactions.index', [
        'category_id' => $userBCategory->id,
    ]))->assertUnprocessable()->assertJsonValidationErrors('category_id');
});
