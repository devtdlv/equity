<?php

use App\DataTransferObjects\CreateFinancialTransactionInput;
use App\DataTransferObjects\PostingInstruction;
use App\DataTransferObjects\PostJournalEntryInput;
use App\DataTransferObjects\ReverseFinancialTransactionInput;
use App\Enums\CategoryType;
use App\Enums\FinancialTransactionType;
use App\Enums\JournalEntryStatus;
use App\Enums\LedgerAccountClassification;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\ImmutableRecordException;
use App\Exceptions\LedgerIntegrityException;
use App\Models\AuditEvent;
use App\Models\Category;
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
use App\Services\LedgerPostingService;
use Database\Seeders\CurrencySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CurrencySeeder::class);
});

test('category service supports listing, creating, renaming, archiving, restoring, duplicate rejection, and audit logging', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $categoryService = app(CategoryService::class);
    $accountService = app(FinancialAccountService::class);
    $transactionService = app(FinancialTransactionService::class);

    $salary = $categoryService->createCategory($user, [
        'name' => '  Salary  ',
        'type' => CategoryType::Income,
    ]);

    $groceries = $categoryService->createCategory($user, [
        'name' => 'Groceries',
        'type' => 'expense',
    ]);

    expect($salary->name)->toBe('Salary')
        ->and($salary->type)->toBe(CategoryType::Income)
        ->and($groceries->type)->toBe(CategoryType::Expense);

    // Create a transaction so Groceries has a linked ledger account to verify name synchronization on rename
    $checking = $accountService->createAccount($user, [
        'name' => 'Primary Checking',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);

    $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $checking->id,
        amount: '250.00',
        entryDate: '2026-10-27',
        description: 'Market run',
        idempotencyKey: '11111111-1111-4111-8111-111111111111',
        categoryId: $groceries->id,
    ));

    // Rename category and verify ledger account name synchronizes
    $renamed = $categoryService->renameCategory($user, $groceries, '  Supermarket & Food  ');
    expect($renamed->name)->toBe('Supermarket & Food')
        ->and($renamed->ledgerAccounts()->first()?->name)->toBe('Supermarket & Food (PHP)');

    // Archive and restore
    $archived = $categoryService->archiveCategory($user, $renamed);
    expect($archived->is_archived)->toBeTrue()
        ->and($archived->archived_at)->not->toBeNull();

    // Filtering active vs all categories
    expect($categoryService->listForUser($user, includeArchived: false))->toHaveCount(1)
        ->and($categoryService->listForUser($user, includeArchived: true))->toHaveCount(2)
        ->and($categoryService->listForUser($user, type: CategoryType::Income))->toHaveCount(1);

    $restored = $categoryService->restoreCategory($user, $archived);
    expect($restored->is_archived)->toBeFalse()
        ->and($restored->archived_at)->toBeNull();

    // Duplicate name for same user and type rejected
    expect(fn () => $categoryService->createCategory($user, [
        'name' => 'Salary',
        'type' => CategoryType::Income,
    ]))->toThrow(LedgerIntegrityException::class, 'already exists');

    // Blank and overly long names rejected
    expect(fn () => $categoryService->createCategory($user, [
        'name' => '   ',
        'type' => CategoryType::Expense,
    ]))->toThrow(LedgerIntegrityException::class, 'Category name is required.');

    expect(fn () => $categoryService->createCategory($user, [
        'name' => str_repeat('A', 81),
        'type' => CategoryType::Expense,
    ]))->toThrow(LedgerIntegrityException::class, 'must not exceed 80 characters');

    // Cross-user rename/archive/restore rejected
    expect(fn () => $categoryService->renameCategory($otherUser, $salary, 'Hijacked'))
        ->toThrow(LedgerIntegrityException::class, 'not accessible');

    expect(fn () => $categoryService->archiveCategory($otherUser, $salary))
        ->toThrow(LedgerIntegrityException::class, 'not accessible');

    expect(fn () => $categoryService->restoreCategory($otherUser, $salary))
        ->toThrow(LedgerIntegrityException::class, 'not accessible');

    // Verify category audit events were recorded
    $categoryAuditActions = AuditEvent::query()
        ->where('user_id', $user->id)
        ->where('auditable_type', Category::class)
        ->orderBy('id')
        ->pluck('action')
        ->all();

    expect($categoryAuditActions)->toBe([
        AuditService::ACTION_CATEGORY_CREATED,
        AuditService::ACTION_CATEGORY_CREATED,
        AuditService::ACTION_CATEGORY_UPDATED,
        AuditService::ACTION_CATEGORY_ARCHIVED,
        AuditService::ACTION_CATEGORY_RESTORED,
    ]);
});

test('income, expense, and transfer transactions create balanced postings, derive accurate asset and credit-card liability balances, and allow negative asset balances', function (): void {
    $user = User::factory()->create();
    $accountService = app(FinancialAccountService::class);
    $categoryService = app(CategoryService::class);
    $transactionService = app(FinancialTransactionService::class);
    $balanceService = app(AccountBalanceService::class);

    $checking = $accountService->createAccount($user, [
        'name' => 'BPI Checking',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);

    $savings = $accountService->createAccount($user, [
        'name' => 'BPI Savings',
        'type' => 'savings',
        'currency_code' => 'PHP',
    ]);

    $creditCard = $accountService->createAccount($user, [
        'name' => 'Rewards Visa',
        'type' => 'credit_card',
        'currency_code' => 'PHP',
    ]);

    $salaryCategory = $categoryService->createCategory($user, [
        'name' => 'Salary',
        'type' => CategoryType::Income,
    ]);

    $refundCategory = $categoryService->createCategory($user, [
        'name' => 'Cashback & Refunds',
        'type' => CategoryType::Income,
    ]);

    $groceriesCategory = $categoryService->createCategory($user, [
        'name' => 'Groceries',
        'type' => CategoryType::Expense,
    ]);

    // 1. Spend ₱1,500.00 from Checking BEFORE any income (proving negative asset balances / overdrafts are allowed)
    $overdraftResult = $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $checking->id,
        amount: '1500.00',
        entryDate: '2026-10-20',
        description: 'Early grocery run',
        idempotencyKey: '22222222-2222-4222-8222-222222222201',
        categoryId: $groceriesCategory->id,
        notes: 'Entered before paycheck',
    ));

    expect($overdraftResult->wasRecentlyCreated)->toBeTrue()
        ->and($overdraftResult->transaction->amount_minor)->toBe(150000)
        ->and($overdraftResult->transaction->notes)->toBe('Entered before paycheck')
        ->and($balanceService->getBalanceForLedgerAccount($checking->ledgerAccount)->amountMinor)->toBe(-150000);

    // Verify Expense posting direction: Debit Expense category ledger, Credit Asset financial account ledger
    $expensePostings = $overdraftResult->journalEntry->postings->keyBy('ledger_account_id');
    $groceriesLedger = LedgerAccount::query()
        ->where('category_id', $groceriesCategory->id)
        ->where('currency_code', 'PHP')
        ->firstOrFail();

    expect($expensePostings[$groceriesLedger->id]->debit_amount_minor)->toBe(150000)
        ->and($expensePostings[$groceriesLedger->id]->credit_amount_minor)->toBe(0)
        ->and($expensePostings[$checking->ledgerAccount->id]->debit_amount_minor)->toBe(0)
        ->and($expensePostings[$checking->ledgerAccount->id]->credit_amount_minor)->toBe(150000);

    // 2. Receive ₱50,000.00 Salary into Checking
    $incomeResult = $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Income,
        financialAccountId: $checking->id,
        amount: '50000.00',
        entryDate: '2026-10-25',
        description: 'Monthly payroll',
        idempotencyKey: '22222222-2222-4222-8222-222222222202',
        categoryId: $salaryCategory->id,
    ));

    $salaryLedger = LedgerAccount::query()
        ->where('category_id', $salaryCategory->id)
        ->where('currency_code', 'PHP')
        ->firstOrFail();

    $incomePostings = $incomeResult->journalEntry->postings->keyBy('ledger_account_id');
    expect($incomePostings[$checking->ledgerAccount->id]->debit_amount_minor)->toBe(5000000)
        ->and($incomePostings[$salaryLedger->id]->credit_amount_minor)->toBe(5000000)
        ->and($balanceService->getBalanceForLedgerAccount($checking->ledgerAccount)->amountMinor)->toBe(4850000);

    // 3. Transfer ₱10,000.00 from Checking to Savings
    $transferResult = $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Transfer,
        financialAccountId: $checking->id,
        transferFinancialAccountId: $savings->id,
        amount: '10000.00',
        entryDate: '2026-10-26',
        description: 'Monthly savings transfer',
        idempotencyKey: '22222222-2222-4222-8222-222222222203',
    ));

    $transferPostings = $transferResult->journalEntry->postings->keyBy('ledger_account_id');
    expect($transferPostings[$savings->ledgerAccount->id]->debit_amount_minor)->toBe(1000000)
        ->and($transferPostings[$checking->ledgerAccount->id]->credit_amount_minor)->toBe(1000000)
        ->and($balanceService->getBalanceForLedgerAccount($checking->ledgerAccount)->amountMinor)->toBe(3850000)
        ->and($balanceService->getBalanceForLedgerAccount($savings->ledgerAccount)->amountMinor)->toBe(1000000);

    // 4. Credit Card Expense (₱4,200.50 on Groceries): Credits Liability (+420050 owed), Debits Expense (+420050)
    $ccExpenseResult = $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $creditCard->id,
        amount: '4200.50',
        entryDate: '2026-10-26',
        description: 'Supermarket on card',
        idempotencyKey: '22222222-2222-4222-8222-222222222204',
        categoryId: $groceriesCategory->id,
    ));

    expect($ccExpenseResult->journalEntry->id)->not->toBeNull()
        ->and($balanceService->getBalanceForLedgerAccount($creditCard->ledgerAccount)->amountMinor)->toBe(420050)
        ->and($balanceService->getBalanceForLedgerAccount($groceriesLedger)->amountMinor)->toBe(570050);

    // 5. Credit Card Rebate/Income (₱200.50 statement credit): Debits Liability (-20050 owed), Credits Income (+20050)
    $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Income,
        financialAccountId: $creditCard->id,
        amount: '200.50',
        entryDate: '2026-10-26',
        description: 'Cashback rebate',
        idempotencyKey: '22222222-2222-4222-8222-222222222205',
        categoryId: $refundCategory->id,
    ));

    expect($balanceService->getBalanceForLedgerAccount($creditCard->ledgerAccount)->amountMinor)->toBe(400000);

    // 6. Pay Credit Card from Checking (₱4,000.00 transfer): Debits Credit Card Liability (-400000 owed -> 0), Credits Checking (-400000)
    $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Transfer,
        financialAccountId: $checking->id,
        transferFinancialAccountId: $creditCard->id,
        amount: '4000.00',
        entryDate: '2026-10-27',
        description: 'Full credit card payment',
        idempotencyKey: '22222222-2222-4222-8222-222222222206',
    ));

    expect($balanceService->getBalanceForLedgerAccount($creditCard->ledgerAccount)->amountMinor)->toBe(0)
        ->and($balanceService->getBalanceForLedgerAccount($checking->ledgerAccount)->amountMinor)->toBe(3450000);
});

test('multi-currency categories create distinct ledger accounts per currency and support JPY zero-decimal precision', function (): void {
    $user = User::factory()->create();
    $accountService = app(FinancialAccountService::class);
    $categoryService = app(CategoryService::class);
    $transactionService = app(FinancialTransactionService::class);
    $balanceService = app(AccountBalanceService::class);

    $usdCard = $accountService->createAccount($user, [
        'name' => 'USD Card',
        'type' => 'credit_card',
        'currency_code' => 'USD',
    ]);

    $jpyCash = $accountService->createAccount($user, [
        'name' => 'JPY Wallet',
        'type' => 'cash',
        'currency_code' => 'JPY',
    ]);

    $diningCategory = $categoryService->createCategory($user, [
        'name' => 'Dining Out',
        'type' => CategoryType::Expense,
    ]);

    // Spend $45.25 in USD
    $usdResult = $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $usdCard->id,
        amount: '45.25',
        entryDate: '2026-10-27',
        description: 'Dinner in New York',
        idempotencyKey: '33333333-3333-4333-8333-333333333301',
        categoryId: $diningCategory->id,
    ));

    // Spend ¥3,200 in JPY
    $jpyResult = $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $jpyCash->id,
        amount: '3200',
        entryDate: '2026-10-27',
        description: 'Ramen in Tokyo',
        idempotencyKey: '33333333-3333-4333-8333-333333333302',
        categoryId: $diningCategory->id,
    ));

    // Second USD expense reuses the existing USD category ledger account
    $usdResult2 = $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $usdCard->id,
        amount: '14.75',
        entryDate: '2026-10-27',
        description: 'Coffee in New York',
        idempotencyKey: '33333333-3333-4333-8333-333333333303',
        categoryId: $diningCategory->id,
    ));

    $categoryLedgers = $diningCategory->ledgerAccounts()->orderBy('currency_code')->get()->keyBy('currency_code');

    expect($categoryLedgers)->toHaveCount(2)
        ->and($categoryLedgers['JPY']->classification)->toBe(LedgerAccountClassification::Expense)
        ->and($categoryLedgers['USD']->classification)->toBe(LedgerAccountClassification::Expense)
        ->and($usdResult->transaction->amount_minor)->toBe(4525)
        ->and($usdResult2->transaction->amount_minor)->toBe(1475)
        ->and($jpyResult->transaction->amount_minor)->toBe(3200)
        ->and($balanceService->getBalanceForLedgerAccount($categoryLedgers['USD'])->amountMinor)->toBe(6000)
        ->and($balanceService->getBalanceForLedgerAccount($categoryLedgers['JPY'])->amountMinor)->toBe(3200);

    // Fractional JPY input is rejected
    expect(fn () => $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $jpyCash->id,
        amount: '3200.50',
        entryDate: '2026-10-27',
        description: 'Invalid fractional JPY',
        idempotencyKey: '33333333-3333-4333-8333-333333333304',
        categoryId: $diningCategory->id,
    )))->toThrow(LedgerIntegrityException::class, 'does not support fractional minor units');
});

test('transaction service rejects non-UUID idempotency keys, cross-currency transfers, same-account transfers, foreign-user records, archived records, wrong category types, and invalid amounts or dates', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $accountService = app(FinancialAccountService::class);
    $categoryService = app(CategoryService::class);
    $transactionService = app(FinancialTransactionService::class);

    $checkingPhp = $accountService->createAccount($user, [
        'name' => 'Checking PHP',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);
    $checkingUsd = $accountService->createAccount($user, [
        'name' => 'Checking USD',
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);
    $archivedAccount = $accountService->createAccount($user, [
        'name' => 'Archived Savings',
        'type' => 'savings',
        'currency_code' => 'PHP',
    ]);
    $accountService->archiveAccount($user, $archivedAccount);

    $otherUserAccount = $accountService->createAccount($otherUser, [
        'name' => 'Other User PHP',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);

    $incomeCategory = $categoryService->createCategory($user, [
        'name' => 'Salary',
        'type' => CategoryType::Income,
    ]);
    $expenseCategory = $categoryService->createCategory($user, [
        'name' => 'Groceries',
        'type' => CategoryType::Expense,
    ]);
    $archivedCategory = $categoryService->createCategory($user, [
        'name' => 'Old Category',
        'type' => CategoryType::Expense,
    ]);
    $categoryService->archiveCategory($user, $archivedCategory);

    $otherUserCategory = $categoryService->createCategory($otherUser, [
        'name' => 'Other User Expense',
        'type' => CategoryType::Expense,
    ]);

    // 0. Non-UUID idempotency keys rejected
    foreach (['', 'not-a-uuid', '12345', 'zzzzzzzz-zzzz-4zzz-8zzz-zzzzzzzzzzzz'] as $invalidKey) {
        expect(fn () => $transactionService->createTransaction(new CreateFinancialTransactionInput(
            user: $user,
            type: FinancialTransactionType::Expense,
            financialAccountId: $checkingPhp->id,
            categoryId: $expenseCategory->id,
            amount: '100.00',
            entryDate: '2026-10-27',
            description: 'Invalid UUID key',
            idempotencyKey: $invalidKey,
        )))->toThrow(LedgerIntegrityException::class, 'valid UUID');
    }

    // 1. Cross-currency transfer rejected
    expect(fn () => $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Transfer,
        financialAccountId: $checkingPhp->id,
        transferFinancialAccountId: $checkingUsd->id,
        amount: '100.00',
        entryDate: '2026-10-27',
        description: 'Cross currency',
        idempotencyKey: '44444444-4444-4444-8444-444444444401',
    )))->toThrow(LedgerIntegrityException::class, 'Cross-currency transfers are not supported yet');

    // 2. Same-account transfer rejected
    expect(fn () => $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Transfer,
        financialAccountId: $checkingPhp->id,
        transferFinancialAccountId: $checkingPhp->id,
        amount: '100.00',
        entryDate: '2026-10-27',
        description: 'Self transfer',
        idempotencyKey: '44444444-4444-4444-8444-444444444402',
    )))->toThrow(LedgerIntegrityException::class, 'must be distinct');

    // 3. Foreign-user primary account rejected
    expect(fn () => $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $otherUserAccount->id,
        categoryId: $expenseCategory->id,
        amount: '100.00',
        entryDate: '2026-10-27',
        description: 'Foreign primary account',
        idempotencyKey: '44444444-4444-4444-8444-444444444403',
    )))->toThrow(LedgerIntegrityException::class, 'not accessible');

    // 4. Foreign-user transfer destination account rejected
    expect(fn () => $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Transfer,
        financialAccountId: $checkingPhp->id,
        transferFinancialAccountId: $otherUserAccount->id,
        amount: '100.00',
        entryDate: '2026-10-27',
        description: 'Foreign destination account',
        idempotencyKey: '44444444-4444-4444-8444-444444444404',
    )))->toThrow(LedgerIntegrityException::class, 'not accessible');

    // 5. Foreign-user category rejected
    expect(fn () => $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $checkingPhp->id,
        categoryId: $otherUserCategory->id,
        amount: '100.00',
        entryDate: '2026-10-27',
        description: 'Foreign category',
        idempotencyKey: '44444444-4444-4444-8444-444444444405',
    )))->toThrow(LedgerIntegrityException::class, 'not accessible');

    // 6. Archived primary or destination account rejected
    expect(fn () => $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $archivedAccount->id,
        categoryId: $expenseCategory->id,
        amount: '100.00',
        entryDate: '2026-10-27',
        description: 'Archived source',
        idempotencyKey: '44444444-4444-4444-8444-444444444406',
    )))->toThrow(LedgerIntegrityException::class, 'Archived financial accounts cannot be used');

    expect(fn () => $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Transfer,
        financialAccountId: $checkingPhp->id,
        transferFinancialAccountId: $archivedAccount->id,
        amount: '100.00',
        entryDate: '2026-10-27',
        description: 'Archived destination',
        idempotencyKey: '44444444-4444-4444-8444-444444444407',
    )))->toThrow(LedgerIntegrityException::class, 'Archived financial accounts cannot be used');

    // 7. Archived category rejected
    expect(fn () => $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $checkingPhp->id,
        categoryId: $archivedCategory->id,
        amount: '100.00',
        entryDate: '2026-10-27',
        description: 'Archived category',
        idempotencyKey: '44444444-4444-4444-8444-444444444408',
    )))->toThrow(LedgerIntegrityException::class, 'Archived categories cannot be used');

    // 8. Wrong category type (Income category on Expense transaction) rejected
    expect(fn () => $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $checkingPhp->id,
        categoryId: $incomeCategory->id,
        amount: '100.00',
        entryDate: '2026-10-27',
        description: 'Wrong category type',
        idempotencyKey: '44444444-4444-4444-8444-444444444409',
    )))->toThrow(LedgerIntegrityException::class, 'Cannot use an [income] category for a [expense] transaction.');

    // 9. Zero, negative, malformed, and oversized amounts rejected
    foreach (['0', '0.00', '-50.00', 'abc', '100.123', '99999999999999999.99'] as $index => $invalidAmount) {
        expect(fn () => $transactionService->createTransaction(new CreateFinancialTransactionInput(
            user: $user,
            type: FinancialTransactionType::Expense,
            financialAccountId: $checkingPhp->id,
            categoryId: $expenseCategory->id,
            amount: $invalidAmount,
            entryDate: '2026-10-27',
            description: 'Invalid amount',
            idempotencyKey: sprintf('44444444-4444-4444-8444-44444444441%d', $index),
        )))->toThrow(LedgerIntegrityException::class);
    }

    // 10. Invalid calendar date rejected
    expect(fn () => $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $checkingPhp->id,
        categoryId: $expenseCategory->id,
        amount: '100.00',
        entryDate: '2026-02-30',
        description: 'Invalid calendar date',
        idempotencyKey: '44444444-4444-4444-8444-444444444499',
    )))->toThrow(LedgerIntegrityException::class, 'valid calendar date');

    expect(FinancialTransaction::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0);
});

test('idempotent retries normalize uppercase/lowercase UUIDs, return the existing transaction even if account or category is later archived, and reject conflicting or corrupted records', function (): void {
    $user = User::factory()->create();
    $accountService = app(FinancialAccountService::class);
    $categoryService = app(CategoryService::class);
    $transactionService = app(FinancialTransactionService::class);
    $ledgerPostingService = app(LedgerPostingService::class);

    $checking = $accountService->createAccount($user, [
        'name' => 'Checking PHP',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);
    $secondChecking = $accountService->createAccount($user, [
        'name' => 'Second Checking PHP',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);

    $groceries = $categoryService->createCategory($user, [
        'name' => 'Groceries',
        'type' => CategoryType::Expense,
    ]);

    $uuidUpper = '550E8400-E29B-41D4-A716-446655440000';
    $uuidLower = '550e8400-e29b-41d4-a716-446655440000';

    $input = new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $checking->id,
        categoryId: $groceries->id,
        amount: '750.50',
        entryDate: '2026-10-27',
        description: 'Weekly groceries',
        notes: 'Fresh produce',
        idempotencyKey: $uuidUpper,
    );

    $first = $transactionService->createTransaction($input);
    $auditCountAfterFirst = AuditEvent::query()->count();

    expect($first->journalEntry->idempotency_key)->toBe($uuidLower);

    // Retry with lowercase UUID and equivalent decimal string ("0750.5" vs "750.50")
    $equivalentInput = new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $checking->id,
        categoryId: $groceries->id,
        amount: '0750.5',
        entryDate: '2026-10-27',
        description: 'Weekly groceries',
        notes: 'Fresh produce',
        idempotencyKey: $uuidLower,
    );
    $second = $transactionService->createTransaction($equivalentInput);

    // Archive both the account and the category, then retry the already-committed identical request:
    // it must still return the committed transaction without requiring them to be unarchived.
    $accountService->archiveAccount($user, $checking);
    $categoryService->archiveCategory($user, $groceries);
    $auditCountAfterArchive = AuditEvent::query()->count();

    $third = $transactionService->createTransaction($equivalentInput);

    // Restore them for subsequent tests
    $accountService->restoreAccount($user, $checking);
    $categoryService->restoreCategory($user, $groceries);

    expect($first->wasRecentlyCreated)->toBeTrue()
        ->and($second->wasRecentlyCreated)->toBeFalse()
        ->and($third->wasRecentlyCreated)->toBeFalse()
        ->and($second->transaction->id)->toBe($first->transaction->id)
        ->and($third->transaction->id)->toBe($first->transaction->id)
        ->and($second->journalEntry->id)->toBe($first->journalEntry->id)
        ->and(FinancialTransaction::query()->count())->toBe(1)
        ->and(JournalEntry::query()->count())->toBe(1)
        ->and($auditCountAfterFirst)->toBeGreaterThan(0)
        ->and(AuditEvent::query()->where('action', AuditService::ACTION_TRANSACTION_RECORDED)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', AuditService::ACTION_JOURNAL_ENTRY_POSTED)->count())->toBe(1)
        ->and($auditCountAfterArchive)->toBeGreaterThan($auditCountAfterFirst);

    // Conflicting retry with different amount, notes, or account
    expect(fn () => $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $checking->id,
        categoryId: $groceries->id,
        amount: '800.00',
        entryDate: '2026-10-27',
        description: 'Weekly groceries',
        notes: 'Fresh produce',
        idempotencyKey: $uuidLower,
    )))->toThrow(IdempotencyConflictException::class);

    expect(fn () => $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $checking->id,
        categoryId: $groceries->id,
        amount: '750.50',
        entryDate: '2026-10-27',
        description: 'Weekly groceries',
        notes: 'Different notes',
        idempotencyKey: $uuidLower,
    )))->toThrow(IdempotencyConflictException::class);

    expect(fn () => $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $secondChecking->id,
        categoryId: $groceries->id,
        amount: '750.50',
        entryDate: '2026-10-27',
        description: 'Weekly groceries',
        notes: 'Fresh produce',
        idempotencyKey: $uuidLower,
    )))->toThrow(IdempotencyConflictException::class);

    // 1. Journal entry without a linked FinancialTransaction must be rejected on retry
    $orphanUuid = '660e8400-e29b-41d4-a716-446655440000';
    $groceriesLedger = LedgerAccount::query()
        ->where('category_id', $groceries->id)
        ->where('currency_code', 'PHP')
        ->firstOrFail();

    $ledgerPostingService->post(new PostJournalEntryInput(
        user: $user,
        currencyCode: 'PHP',
        entryDate: '2026-10-27',
        description: 'Standalone journal entry',
        idempotencyKey: $orphanUuid,
        postings: [
            new PostingInstruction(ledgerAccountId: $groceriesLedger->id, debitAmountMinor: 50000),
            new PostingInstruction(ledgerAccountId: $checking->ledgerAccount->id, creditAmountMinor: 50000),
        ],
    ));

    expect(fn () => $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $checking->id,
        categoryId: $groceries->id,
        amount: '500.00',
        entryDate: '2026-10-27',
        description: 'Standalone journal entry',
        idempotencyKey: $orphanUuid,
    )))->toThrow(IdempotencyConflictException::class, 'without a linked financial transaction');

    // 2. Journal entry whose posting fingerprint does not match the linked FinancialTransaction must be rejected
    $mismatchedPostingUuid = '770e8400-e29b-41d4-a716-446655440000';
    $canonicalRef = $transactionService->computeCanonicalTransactionReference(
        userId: $user->id,
        type: FinancialTransactionType::Expense,
        financialAccountId: $checking->id,
        transferFinancialAccountId: null,
        categoryId: $groceries->id,
        currencyCode: 'PHP',
        amountMinor: 50000,
        entryDate: '2026-10-27',
        description: 'Mismatched posting entry',
        notes: null,
    );

    // Post journal entry using $secondChecking's ledger account, but link FinancialTransaction to $checking
    $mismatchedJournal = $ledgerPostingService->post(new PostJournalEntryInput(
        user: $user,
        currencyCode: 'PHP',
        entryDate: '2026-10-27',
        description: 'Mismatched posting entry',
        idempotencyKey: $mismatchedPostingUuid,
        postings: [
            new PostingInstruction(ledgerAccountId: $groceriesLedger->id, debitAmountMinor: 50000),
            new PostingInstruction(ledgerAccountId: $secondChecking->ledgerAccount->id, creditAmountMinor: 50000),
        ],
        externalReference: $canonicalRef,
    ))->journalEntry;

    FinancialTransaction::query()->create([
        'user_id' => $user->id,
        'journal_entry_id' => $mismatchedJournal->id,
        'financial_account_id' => $checking->id,
        'transfer_financial_account_id' => null,
        'category_id' => $groceries->id,
        'type' => FinancialTransactionType::Expense,
        'currency_code' => 'PHP',
        'amount_minor' => 50000,
        'entry_date' => '2026-10-27',
        'description' => 'Mismatched posting entry',
        'notes' => null,
    ]);

    expect(fn () => $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $checking->id,
        categoryId: $groceries->id,
        amount: '500.00',
        entryDate: '2026-10-27',
        description: 'Mismatched posting entry',
        idempotencyKey: $mismatchedPostingUuid,
    )))->toThrow(IdempotencyConflictException::class, 'posting fingerprint does not match');
});

test('partial failure on transaction or audit persistence rolls back journal, postings, category ledger, transaction, and audit events atomically', function (): void {
    $user = User::factory()->create();
    $accountService = app(FinancialAccountService::class);
    $categoryService = app(CategoryService::class);
    $transactionService = app(FinancialTransactionService::class);

    $checking = $accountService->createAccount($user, [
        'name' => 'Checking PHP',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);

    $utilities = $categoryService->createCategory($user, [
        'name' => 'Utilities',
        'type' => CategoryType::Expense,
    ]);

    $auditCountBefore = AuditEvent::query()->count();

    // 1. Failure during FinancialTransaction::creating
    $failTransactionOnce = true;
    FinancialTransaction::creating(function () use (&$failTransactionOnce): void {
        if ($failTransactionOnce) {
            $failTransactionOnce = false;

            throw new RuntimeException('Simulated failure while creating FinancialTransaction');
        }
    });

    $input = new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $checking->id,
        categoryId: $utilities->id,
        amount: '2400.00',
        entryDate: '2026-10-27',
        description: 'Electricity bill',
        idempotencyKey: '880e8400-e29b-41d4-a716-446655440001',
    );

    expect(fn () => $transactionService->createTransaction($input))
        ->toThrow(RuntimeException::class, 'Simulated failure while creating FinancialTransaction');

    expect(FinancialTransaction::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and(JournalPosting::query()->count())->toBe(0)
        ->and(LedgerAccount::query()->where('category_id', $utilities->id)->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe($auditCountBefore);

    // 2. Failure during AuditEvent::creating for transaction.recorded
    $failAuditOnce = true;
    AuditEvent::creating(function (AuditEvent $event) use (&$failAuditOnce): void {
        if ($failAuditOnce && $event->action === AuditService::ACTION_TRANSACTION_RECORDED) {
            $failAuditOnce = false;

            throw new RuntimeException('Simulated failure while recording transaction audit event');
        }
    });

    expect(fn () => $transactionService->createTransaction($input))
        ->toThrow(RuntimeException::class, 'Simulated failure while recording transaction audit event');

    expect(FinancialTransaction::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and(JournalPosting::query()->count())->toBe(0)
        ->and(LedgerAccount::query()->where('category_id', $utilities->id)->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe($auditCountBefore);

    // 3. Clean retry after rolled-back failures succeeds
    $retryResult = $transactionService->createTransaction($input);

    expect($retryResult->wasRecentlyCreated)->toBeTrue()
        ->and($retryResult->transaction->amount_minor)->toBe(240000)
        ->and($retryResult->journalEntry->postings)->toHaveCount(2);

    expect(fn () => $retryResult->transaction->update(['description' => 'Tampered']))
        ->toThrow(ImmutableRecordException::class);
});

test('concurrent category ledger account creation rolls back failed attempt and resolves winning row on retry while rethrowing unrelated unique violations', function (): void {
    $user = User::factory()->create();
    $categoryService = app(CategoryService::class);
    $transactionService = app(FinancialTransactionService::class);

    $category = $categoryService->createCategory($user, [
        'name' => 'Freelance',
        'type' => CategoryType::Income,
    ]);

    // Simulate a concurrent winner inserting the (category_id, currency_code) ledger account
    // right as the first attempt's LedgerAccount::create runs, causing a unique-constraint exception.
    // The outer transaction rolls back and the second attempt locks and returns the winning row.
    $raceInserted = false;
    LedgerAccount::creating(function () use ($user, $category, &$raceInserted): void {
        if ($raceInserted) {
            return;
        }
        $raceInserted = true;

        DB::table('ledger_accounts')->insert([
            'user_id' => $user->id,
            'financial_account_id' => null,
            'category_id' => $category->id,
            'code' => "CAT-{$category->id}-USD",
            'name' => 'Freelance (USD)',
            'classification' => LedgerAccountClassification::Income->value,
            'currency_code' => 'USD',
            'is_system' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Simulate the database unique constraint violation on ledger_accounts_category_id_currency_code_unique
        // without aborting the outer test harness transaction
        throw new QueryException(
            'sqlite',
            'insert into "ledger_accounts" ...',
            [],
            new Exception('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: ledger_accounts.category_id, ledger_accounts.currency_code', 19),
        );
    });

    $resolved = $transactionService->resolveOrCreateCategoryLedgerAccount($user, $category, 'USD');

    expect($resolved->category_id)->toBe($category->id)
        ->and($resolved->currency_code)->toBe('USD')
        ->and(LedgerAccount::query()->where('category_id', $category->id)->count())->toBe(1);

    // Unrelated unique violation (e.g. on ledger_accounts.user_id, ledger_accounts.code) is NOT swallowed
    $secondCategory = $categoryService->createCategory($user, [
        'name' => 'Consulting',
        'type' => CategoryType::Income,
    ]);

    $throwUnrelatedOnce = true;
    LedgerAccount::creating(function () use (&$throwUnrelatedOnce): void {
        if (! $throwUnrelatedOnce) {
            return;
        }
        $throwUnrelatedOnce = false;

        throw new QueryException(
            'sqlite',
            'insert into "ledger_accounts" ...',
            [],
            new Exception('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: ledger_accounts.user_id, ledger_accounts.code', 19),
        );
    });

    expect(fn () => $transactionService->resolveOrCreateCategoryLedgerAccount($user, $secondCategory, 'USD'))
        ->toThrow(QueryException::class);
});

test('nested transaction idempotency race rolls back outer transaction before recovering the completed transaction in a fresh snapshot', function (): void {
    $user = User::factory()->create();
    $accountService = app(FinancialAccountService::class);
    $categoryService = app(CategoryService::class);
    $transactionService = app(FinancialTransactionService::class);
    $ledgerPostingService = app(LedgerPostingService::class);

    $checking = $accountService->createAccount($user, [
        'name' => 'Checking PHP',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);
    $groceries = $categoryService->createCategory($user, [
        'name' => 'Groceries',
        'type' => CategoryType::Expense,
    ]);

    $raceUuid = '990e8400-e29b-41d4-a716-446655440099';
    $input = new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $checking->id,
        categoryId: $groceries->id,
        amount: '300.00',
        entryDate: '2026-10-27',
        description: 'Concurrent grocery purchase',
        notes: 'Raced submission',
        idempotencyKey: $raceUuid,
    );

    // Simulate a concurrent winner committing on a second connection right after Attempt 1's outer transaction
    // rolls back on the unique constraint violation. Because RefreshDatabase wraps the test in a level-1 transaction,
    // we listen for the query that starts Attempt 2 (selecting from journal_entries by idempotency_key) after Attempt 1 threw.
    $throwOnFirstAttempt = true;
    $seedWinnerOnSecondAttempt = false;

    JournalEntry::creating(function () use (&$throwOnFirstAttempt, &$seedWinnerOnSecondAttempt): void {
        if (! $throwOnFirstAttempt) {
            return;
        }
        $throwOnFirstAttempt = false;
        $seedWinnerOnSecondAttempt = true;

        throw new QueryException(
            'sqlite',
            'insert into "journal_entries" ...',
            [],
            new Exception('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: journal_entries.user_id, journal_entries.idempotency_key', 19),
        );
    });

    DB::listen(function ($query) use (
        $user,
        $checking,
        $groceries,
        $raceUuid,
        $transactionService,
        $ledgerPostingService,
        &$seedWinnerOnSecondAttempt
    ): void {
        if (! $seedWinnerOnSecondAttempt || ! str_contains($query->sql, 'from "journal_entries"')) {
            return;
        }
        $seedWinnerOnSecondAttempt = false;

        $categoryLedger = LedgerAccount::query()->create([
            'user_id' => $user->id,
            'financial_account_id' => null,
            'category_id' => $groceries->id,
            'code' => "CAT-{$groceries->id}-PHP",
            'name' => 'Groceries (PHP)',
            'classification' => LedgerAccountClassification::Expense,
            'currency_code' => 'PHP',
            'is_system' => false,
        ]);

        $canonicalRef = $transactionService->computeCanonicalTransactionReference(
            userId: $user->id,
            type: FinancialTransactionType::Expense,
            financialAccountId: $checking->id,
            transferFinancialAccountId: null,
            categoryId: $groceries->id,
            currencyCode: 'PHP',
            amountMinor: 30000,
            entryDate: '2026-10-27',
            description: 'Concurrent grocery purchase',
            notes: 'Raced submission',
        );

        $postings = [
            new PostingInstruction(ledgerAccountId: $categoryLedger->id, debitAmountMinor: 30000),
            new PostingInstruction(ledgerAccountId: $checking->ledgerAccount->id, creditAmountMinor: 30000),
        ];

        $fingerprint = $ledgerPostingService->computeRequestFingerprint(
            userId: $user->id,
            currencyCode: 'PHP',
            entryDate: '2026-10-27',
            description: 'Concurrent grocery purchase',
            externalReference: $canonicalRef,
            postings: $postings,
        );

        $entryId = DB::table('journal_entries')->insertGetId([
            'user_id' => $user->id,
            'currency_code' => 'PHP',
            'idempotency_key' => $raceUuid,
            'request_fingerprint' => $fingerprint,
            'entry_date' => '2026-10-27',
            'description' => 'Concurrent grocery purchase',
            'external_reference' => $canonicalRef,
            'status' => JournalEntryStatus::Posted->value,
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($postings as $posting) {
            DB::table('journal_postings')->insert([
                'journal_entry_id' => $entryId,
                'ledger_account_id' => $posting->ledgerAccountId,
                'debit_amount_minor' => $posting->debitAmountMinor,
                'credit_amount_minor' => $posting->creditAmountMinor,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('financial_transactions')->insert([
            'user_id' => $user->id,
            'journal_entry_id' => $entryId,
            'financial_account_id' => $checking->id,
            'transfer_financial_account_id' => null,
            'category_id' => $groceries->id,
            'type' => FinancialTransactionType::Expense->value,
            'currency_code' => 'PHP',
            'amount_minor' => 30000,
            'entry_date' => '2026-10-27',
            'description' => 'Concurrent grocery purchase',
            'notes' => 'Raced submission',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $recovered = $transactionService->createTransaction($input);

    expect($recovered->wasRecentlyCreated)->toBeFalse()
        ->and($recovered->transaction->amount_minor)->toBe(30000)
        ->and($recovered->journalEntry->idempotency_key)->toBe($raceUuid)
        ->and(FinancialTransaction::query()->count())->toBe(1)
        ->and(JournalEntry::query()->count())->toBe(1);
});

test('reversing expense, income, and transfer transactions creates exact opposite balanced postings, restores account and category balances, preserves original records, and records audit events', function (): void {
    $user = User::factory()->create();
    $accountService = app(FinancialAccountService::class);
    $categoryService = app(CategoryService::class);
    $transactionService = app(FinancialTransactionService::class);
    $balanceService = app(AccountBalanceService::class);

    $checking = $accountService->createAccount($user, [
        'name' => 'Primary Checking',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);
    $savings = $accountService->createAccount($user, [
        'name' => 'Emergency Savings',
        'type' => 'savings',
        'currency_code' => 'PHP',
    ]);
    $creditCard = $accountService->createAccount($user, [
        'name' => 'Travel Visa',
        'type' => 'credit_card',
        'currency_code' => 'PHP',
    ]);

    $salaryCategory = $categoryService->createCategory($user, [
        'name' => 'Salary',
        'type' => CategoryType::Income,
    ]);
    $groceriesCategory = $categoryService->createCategory($user, [
        'name' => 'Groceries',
        'type' => CategoryType::Expense,
    ]);

    // 1. Create and reverse an Income transaction (₱40,000.00 -> 4,000,000 minor units)
    $incomeCreated = $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Income,
        financialAccountId: $checking->id,
        categoryId: $salaryCategory->id,
        amount: '40000.00',
        entryDate: '2026-10-25',
        description: 'October Payroll',
        notes: 'Initial deposit',
        idempotencyKey: 'a1000000-0000-4000-8000-000000000001',
    ));

    $salaryLedger = LedgerAccount::query()
        ->where('category_id', $salaryCategory->id)
        ->where('currency_code', 'PHP')
        ->firstOrFail();

    expect($balanceService->getBalanceForLedgerAccount($checking->ledgerAccount)->amountMinor)->toBe(4000000)
        ->and($balanceService->getBalanceForLedgerAccount($salaryLedger)->amountMinor)->toBe(4000000);

    $incomeOriginalPostingsSnapshot = $incomeCreated->journalEntry->postings
        ->map(static fn (JournalPosting $p): array => [
            'id' => $p->id,
            'ledger_account_id' => $p->ledger_account_id,
            'debit' => $p->debit_amount_minor,
            'credit' => $p->credit_amount_minor,
        ])
        ->all();

    $incomeReversal = $transactionService->reverseTransaction(new ReverseFinancialTransactionInput(
        user: $user,
        originalTransactionId: $incomeCreated->transaction->id,
        idempotencyKey: 'a1000000-0000-4000-8000-000000000002',
        reason: '  Entered on wrong date  ',
    ));

    // Income reversal removes the original income effect on both Checking and Salary ledger accounts
    expect($incomeReversal->wasRecentlyCreated)->toBeTrue()
        ->and($incomeReversal->reversalTransaction->isReversal())->toBeTrue()
        ->and($incomeReversal->reversalTransaction->isReversed())->toBeFalse()
        ->and($incomeReversal->reversalTransaction->reversed_transaction_id)->toBe($incomeCreated->transaction->id)
        ->and($incomeReversal->reversalTransaction->reversal_reason)->toBe('Entered on wrong date')
        ->and($incomeReversal->reversalTransaction->description)->toBe('Reversal: October Payroll')
        ->and($incomeReversal->reversalTransaction->amount_minor)->toBe(4000000)
        ->and($incomeReversal->reversalTransaction->currency_code)->toBe('PHP')
        ->and($incomeReversal->originalTransaction->isReversed())->toBeTrue()
        ->and($incomeReversal->originalTransaction->isReversal())->toBeFalse()
        ->and($balanceService->getBalanceForLedgerAccount($checking->ledgerAccount)->amountMinor)->toBe(0)
        ->and($balanceService->getBalanceForLedgerAccount($salaryLedger)->amountMinor)->toBe(0);

    // Original journal postings remain completely unchanged
    $incomeOriginalPostingsAfter = $incomeCreated->journalEntry->fresh('postings')?->postings
        ->map(static fn (JournalPosting $p): array => [
            'id' => $p->id,
            'ledger_account_id' => $p->ledger_account_id,
            'debit' => $p->debit_amount_minor,
            'credit' => $p->credit_amount_minor,
        ])
        ->all();
    expect($incomeOriginalPostingsAfter)->toBe($incomeOriginalPostingsSnapshot);

    // Reversal postings are exact debit/credit opposites and balanced
    $incomeRevPostings = $incomeReversal->reversalJournalEntry->postings->keyBy('ledger_account_id');
    expect($incomeRevPostings)->toHaveCount(2)
        ->and($incomeRevPostings[$checking->ledgerAccount->id]->debit_amount_minor)->toBe(0)
        ->and($incomeRevPostings[$checking->ledgerAccount->id]->credit_amount_minor)->toBe(4000000)
        ->and($incomeRevPostings[$salaryLedger->id]->debit_amount_minor)->toBe(4000000)
        ->and($incomeRevPostings[$salaryLedger->id]->credit_amount_minor)->toBe(0)
        ->and($incomeReversal->reversalJournalEntry->postings->sum('debit_amount_minor'))
        ->toBe($incomeReversal->reversalJournalEntry->postings->sum('credit_amount_minor'));

    // 2. Create and reverse an Expense transaction (₱3,500.75 -> 350,075 minor units)
    $expenseCreated = $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $checking->id,
        categoryId: $groceriesCategory->id,
        amount: '3500.75',
        entryDate: '2026-10-26',
        description: 'Supermarket haul',
        idempotencyKey: 'a1000000-0000-4000-8000-000000000003',
    ));

    $groceriesLedger = LedgerAccount::query()
        ->where('category_id', $groceriesCategory->id)
        ->where('currency_code', 'PHP')
        ->firstOrFail();

    expect($balanceService->getBalanceForLedgerAccount($checking->ledgerAccount)->amountMinor)->toBe(-350075)
        ->and($balanceService->getBalanceForLedgerAccount($groceriesLedger)->amountMinor)->toBe(350075);

    // Archive the category and account before reversing: reversal of an already-posted transaction must still succeed
    $categoryService->archiveCategory($user, $groceriesCategory);
    $accountService->archiveAccount($user, $checking);

    $expenseReversal = $transactionService->reverseTransaction(new ReverseFinancialTransactionInput(
        user: $user,
        originalTransactionId: $expenseCreated->transaction->id,
        idempotencyKey: 'a1000000-0000-4000-8000-000000000004',
        reason: null,
    ));

    // Restore account and category for remaining assertions
    $accountService->restoreAccount($user, $checking);
    $categoryService->restoreCategory($user, $groceriesCategory);

    // Expense reversal restores the source account balance and removes the expense category balance
    expect($expenseReversal->reversalTransaction->reversal_reason)->toBeNull()
        ->and($balanceService->getBalanceForLedgerAccount($checking->ledgerAccount)->amountMinor)->toBe(0)
        ->and($balanceService->getBalanceForLedgerAccount($groceriesLedger)->amountMinor)->toBe(0);

    $expenseRevPostings = $expenseReversal->reversalJournalEntry->postings->keyBy('ledger_account_id');
    expect($expenseRevPostings[$checking->ledgerAccount->id]->debit_amount_minor)->toBe(350075)
        ->and($expenseRevPostings[$checking->ledgerAccount->id]->credit_amount_minor)->toBe(0)
        ->and($expenseRevPostings[$groceriesLedger->id]->debit_amount_minor)->toBe(0)
        ->and($expenseRevPostings[$groceriesLedger->id]->credit_amount_minor)->toBe(350075);

    // 3. Create and reverse a Transfer transaction (₱12,000.00 from Checking to Credit Card)
    $transferCreated = $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Transfer,
        financialAccountId: $checking->id,
        transferFinancialAccountId: $creditCard->id,
        amount: '12000.00',
        entryDate: '2026-10-27',
        description: 'Credit card payment',
        idempotencyKey: 'a1000000-0000-4000-8000-000000000005',
    ));

    expect($balanceService->getBalanceForLedgerAccount($checking->ledgerAccount)->amountMinor)->toBe(-1200000)
        ->and($balanceService->getBalanceForLedgerAccount($creditCard->ledgerAccount)->amountMinor)->toBe(-1200000);

    $transferReversal = $transactionService->reverseTransaction(new ReverseFinancialTransactionInput(
        user: $user,
        originalTransactionId: $transferCreated->transaction->id,
        idempotencyKey: 'a1000000-0000-4000-8000-000000000006',
        reason: 'Payment returned',
    ));

    // Transfer reversal restores both account balances
    expect($balanceService->getBalanceForLedgerAccount($checking->ledgerAccount)->amountMinor)->toBe(0)
        ->and($balanceService->getBalanceForLedgerAccount($creditCard->ledgerAccount)->amountMinor)->toBe(0);

    $transferRevPostings = $transferReversal->reversalJournalEntry->postings->keyBy('ledger_account_id');
    expect($transferRevPostings[$checking->ledgerAccount->id]->debit_amount_minor)->toBe(1200000)
        ->and($transferRevPostings[$checking->ledgerAccount->id]->credit_amount_minor)->toBe(0)
        ->and($transferRevPostings[$creditCard->ledgerAccount->id]->debit_amount_minor)->toBe(0)
        ->and($transferRevPostings[$creditCard->ledgerAccount->id]->credit_amount_minor)->toBe(1200000);

    // 4. Verify scopes distinguish non-reversal, only-reversals, and active transactions
    expect(FinancialTransaction::query()->where('user_id', $user->id)->count())->toBe(6)
        ->and(FinancialTransaction::query()->where('user_id', $user->id)->nonReversal()->count())->toBe(3)
        ->and(FinancialTransaction::query()->where('user_id', $user->id)->onlyReversals()->count())->toBe(3)
        ->and(FinancialTransaction::query()->where('user_id', $user->id)->active()->count())->toBe(0);

    // 5. Verify audit event was recorded with expected metadata
    $reversalAudit = AuditEvent::query()
        ->where('user_id', $user->id)
        ->where('action', AuditService::ACTION_TRANSACTION_REVERSED)
        ->where('auditable_id', $incomeReversal->reversalTransaction->id)
        ->firstOrFail();

    expect($reversalAudit->auditable_type)->toBe(FinancialTransaction::class)
        ->and($reversalAudit->metadata['original_transaction_id'])->toBe($incomeCreated->transaction->id)
        ->and($reversalAudit->metadata['original_journal_entry_id'])->toBe($incomeCreated->journalEntry->id)
        ->and($reversalAudit->metadata['reversal_transaction_id'])->toBe($incomeReversal->reversalTransaction->id)
        ->and($reversalAudit->metadata['reversal_journal_entry_id'])->toBe($incomeReversal->reversalJournalEntry->id)
        ->and($reversalAudit->metadata['reversal_reason'])->toBe('Entered on wrong date')
        ->and($reversalAudit->metadata['idempotency_key'])->toBe('a1000000-0000-4000-8000-000000000002');
});

test('reversal idempotency replays identical requests, rejects conflicting payloads, prevents double reversal with a different UUID, rejects reversal of reversal, and enforces tenant isolation', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $accountService = app(FinancialAccountService::class);
    $categoryService = app(CategoryService::class);
    $transactionService = app(FinancialTransactionService::class);
    $balanceService = app(AccountBalanceService::class);

    $checkingA = $accountService->createAccount($userA, [
        'name' => 'User A Checking',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);
    $groceriesA = $categoryService->createCategory($userA, [
        'name' => 'Groceries',
        'type' => CategoryType::Expense,
    ]);

    $txA1 = $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $userA,
        type: FinancialTransactionType::Expense,
        financialAccountId: $checkingA->id,
        categoryId: $groceriesA->id,
        amount: '1250.00',
        entryDate: '2026-10-27',
        description: 'Grocery order #1',
        idempotencyKey: 'b1000000-0000-4000-8000-000000000001',
    ))->transaction;

    $txA2 = $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $userA,
        type: FinancialTransactionType::Expense,
        financialAccountId: $checkingA->id,
        categoryId: $groceriesA->id,
        amount: '900.00',
        entryDate: '2026-10-27',
        description: 'Grocery order #2',
        idempotencyKey: 'b1000000-0000-4000-8000-000000000002',
    ))->transaction;

    // 1. Cross-user reversal is rejected
    expect(fn () => $transactionService->reverseTransaction(new ReverseFinancialTransactionInput(
        user: $userB,
        originalTransactionId: $txA1->id,
        idempotencyKey: 'b1000000-0000-4000-8000-000000000099',
        reason: 'Unauthorized attempt',
    )))->toThrow(LedgerIntegrityException::class, 'not accessible');

    // Non-existent or invalid transaction ID is rejected
    expect(fn () => $transactionService->reverseTransaction(new ReverseFinancialTransactionInput(
        user: $userA,
        originalTransactionId: 999999,
        idempotencyKey: 'b1000000-0000-4000-8000-000000000098',
    )))->toThrow(LedgerIntegrityException::class, 'not accessible');

    expect(fn () => $transactionService->reverseTransaction(new ReverseFinancialTransactionInput(
        user: $userA,
        originalTransactionId: 0,
        idempotencyKey: 'b1000000-0000-4000-8000-000000000097',
    )))->toThrow(LedgerIntegrityException::class, 'not accessible');

    // 2. First reversal succeeds
    $revUuidUpper = 'B2000000-0000-4000-8000-000000000001';
    $revUuidLower = 'b2000000-0000-4000-8000-000000000001';

    $firstRev = $transactionService->reverseTransaction(new ReverseFinancialTransactionInput(
        user: $userA,
        originalTransactionId: $txA1->id,
        idempotencyKey: $revUuidUpper,
        reason: '  Duplicate charge  ',
    ));

    $auditCountAfterFirstRev = AuditEvent::query()->count();

    // 3. Same UUID and canonical payload safely replays without creating another journal entry or audit event
    $replayRev = $transactionService->reverseTransaction(new ReverseFinancialTransactionInput(
        user: $userA,
        originalTransactionId: $txA1->id,
        idempotencyKey: $revUuidLower,
        reason: 'Duplicate charge',
    ));

    expect($firstRev->wasRecentlyCreated)->toBeTrue()
        ->and($replayRev->wasRecentlyCreated)->toBeFalse()
        ->and($replayRev->reversalTransaction->id)->toBe($firstRev->reversalTransaction->id)
        ->and($replayRev->reversalJournalEntry->id)->toBe($firstRev->reversalJournalEntry->id)
        ->and(AuditEvent::query()->count())->toBe($auditCountAfterFirstRev)
        ->and($balanceService->getBalanceForLedgerAccount($checkingA->ledgerAccount)->amountMinor)->toBe(-90000);

    // 4. Same UUID with changed payload (different reason or different originalTransactionId) is rejected with IdempotencyConflictException
    expect(fn () => $transactionService->reverseTransaction(new ReverseFinancialTransactionInput(
        user: $userA,
        originalTransactionId: $txA1->id,
        idempotencyKey: $revUuidLower,
        reason: 'Changed reason',
    )))->toThrow(IdempotencyConflictException::class, 'different reversal payload');

    expect(fn () => $transactionService->reverseTransaction(new ReverseFinancialTransactionInput(
        user: $userA,
        originalTransactionId: $txA2->id,
        idempotencyKey: $revUuidLower,
        reason: 'Duplicate charge',
    )))->toThrow(IdempotencyConflictException::class, 'different reversal payload');

    // Reusing an ordinary transaction's UUID for a reversal (or vice versa) is rejected with IdempotencyConflictException
    expect(fn () => $transactionService->reverseTransaction(new ReverseFinancialTransactionInput(
        user: $userA,
        originalTransactionId: $txA2->id,
        idempotencyKey: 'b1000000-0000-4000-8000-000000000001',
    )))->toThrow(IdempotencyConflictException::class, 'non-reversal financial transaction');

    expect(fn () => $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $userA,
        type: FinancialTransactionType::Expense,
        financialAccountId: $checkingA->id,
        categoryId: $groceriesA->id,
        amount: '1250.00',
        entryDate: '2026-10-27',
        description: 'Reversal: Grocery order #1',
        idempotencyKey: $revUuidLower,
    )))->toThrow(IdempotencyConflictException::class, 'transaction reversal');

    // 5. Different UUID cannot reverse an already-reversed transaction
    expect(fn () => $transactionService->reverseTransaction(new ReverseFinancialTransactionInput(
        user: $userA,
        originalTransactionId: $txA1->id,
        idempotencyKey: 'b2000000-0000-4000-8000-000000000002',
        reason: 'Second reversal attempt',
    )))->toThrow(LedgerIntegrityException::class, 'already been reversed');

    // 6. Reversal of a reversal is rejected
    expect(fn () => $transactionService->reverseTransaction(new ReverseFinancialTransactionInput(
        user: $userA,
        originalTransactionId: $firstRev->reversalTransaction->id,
        idempotencyKey: 'b2000000-0000-4000-8000-000000000003',
        reason: 'Reverse the reversal',
    )))->toThrow(LedgerIntegrityException::class, 'A reversal transaction cannot be reversed.');

    // 7. Invalid reversal reason (> 255 chars) or invalid UUID leaves no partial records
    $txCountBeforeInvalid = FinancialTransaction::query()->count();
    $journalCountBeforeInvalid = JournalEntry::query()->count();

    expect(fn () => $transactionService->reverseTransaction(new ReverseFinancialTransactionInput(
        user: $userA,
        originalTransactionId: $txA2->id,
        idempotencyKey: 'b2000000-0000-4000-8000-000000000004',
        reason: str_repeat('R', 256),
    )))->toThrow(LedgerIntegrityException::class, 'Reversal reason must not exceed 255 characters.');

    expect(fn () => $transactionService->reverseTransaction(new ReverseFinancialTransactionInput(
        user: $userA,
        originalTransactionId: $txA2->id,
        idempotencyKey: 'not-a-valid-uuid',
    )))->toThrow(LedgerIntegrityException::class, 'valid UUID');

    expect(FinancialTransaction::query()->count())->toBe($txCountBeforeInvalid)
        ->and(JournalEntry::query()->count())->toBe($journalCountBeforeInvalid);

    // 8. Partial failure during reversal rolls back the reversal journal entry, postings, and audit event atomically
    $failAuditOnce = true;
    AuditEvent::creating(function (AuditEvent $event) use (&$failAuditOnce): void {
        if ($failAuditOnce && $event->action === AuditService::ACTION_TRANSACTION_REVERSED) {
            $failAuditOnce = false;

            throw new RuntimeException('Simulated failure during reversal audit logging');
        }
    });

    expect(fn () => $transactionService->reverseTransaction(new ReverseFinancialTransactionInput(
        user: $userA,
        originalTransactionId: $txA2->id,
        idempotencyKey: 'b2000000-0000-4000-8000-000000000005',
        reason: 'Atomic rollback test',
    )))->toThrow(RuntimeException::class, 'Simulated failure during reversal audit logging');

    expect($txA2->fresh()?->isReversed())->toBeFalse()
        ->and(FinancialTransaction::query()->count())->toBe($txCountBeforeInvalid)
        ->and(JournalEntry::query()->count())->toBe($journalCountBeforeInvalid);

    // Subsequent retry succeeds cleanly
    $txA2Reversed = $transactionService->reverseTransaction(new ReverseFinancialTransactionInput(
        user: $userA,
        originalTransactionId: $txA2->id,
        idempotencyKey: 'b2000000-0000-4000-8000-000000000005',
        reason: 'Atomic rollback test',
    ));

    expect($txA2Reversed->wasRecentlyCreated)->toBeTrue()
        ->and($txA2->fresh()?->isReversed())->toBeTrue()
        ->and($balanceService->getBalanceForLedgerAccount($checkingA->ledgerAccount)->amountMinor)->toBe(0);
});

test('reversal rejects inconsistent original journal data and translates concurrent unique-constraint races on reversed_transaction_id into domain errors', function (): void {
    $user = User::factory()->create();
    $accountService = app(FinancialAccountService::class);
    $categoryService = app(CategoryService::class);
    $transactionService = app(FinancialTransactionService::class);
    $postingService = app(LedgerPostingService::class);

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
    $groceries = $categoryService->createCategory($user, [
        'name' => 'Groceries',
        'type' => CategoryType::Expense,
    ]);

    // 1. Original transaction whose amount_minor does not match its journal entry postings total is rejected
    $journalMismatch = $postingService->post(new PostJournalEntryInput(
        user: $user,
        currencyCode: 'PHP',
        entryDate: '2026-10-28',
        description: 'Mismatched journal total',
        idempotencyKey: 'c1000000-0000-4000-8000-000000000001',
        postings: [
            new PostingInstruction(ledgerAccountId: $savings->ledgerAccount->id, debitAmountMinor: 10000),
            new PostingInstruction(ledgerAccountId: $checking->ledgerAccount->id, creditAmountMinor: 10000),
        ],
    ))->journalEntry;

    $mismatchedTx = FinancialTransaction::query()->create([
        'user_id' => $user->id,
        'journal_entry_id' => $journalMismatch->id,
        'financial_account_id' => $checking->id,
        'transfer_financial_account_id' => $savings->id,
        'category_id' => null,
        'type' => FinancialTransactionType::Transfer,
        'currency_code' => 'PHP',
        'amount_minor' => 50000,
        'entry_date' => '2026-10-28',
        'description' => 'Mismatched amount tx',
    ]);

    expect(fn () => $transactionService->reverseTransaction(new ReverseFinancialTransactionInput(
        user: $user,
        originalTransactionId: $mismatchedTx->id,
        idempotencyKey: 'c1000000-0000-4000-8000-000000000002',
    )))->toThrow(LedgerIntegrityException::class, 'Original journal entry postings do not match the transaction amount.');

    // 2. Concurrent unique constraint race on financial_transactions_reversed_transaction_id_unique
    // is translated into LedgerIntegrityException ('already been reversed') without leaking SQL errors
    $validTx = $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Expense,
        financialAccountId: $checking->id,
        categoryId: $groceries->id,
        amount: '600.00',
        entryDate: '2026-10-28',
        description: 'Concurrent reversal target',
        idempotencyKey: 'c1000000-0000-4000-8000-000000000003',
    ))->transaction;

    $throwConcurrentDuplicateOnce = true;
    FinancialTransaction::creating(function (FinancialTransaction $tx) use (&$throwConcurrentDuplicateOnce): void {
        if (! $throwConcurrentDuplicateOnce || $tx->reversed_transaction_id === null) {
            return;
        }
        $throwConcurrentDuplicateOnce = false;

        throw new QueryException(
            'sqlite',
            'insert into "financial_transactions" ...',
            [],
            new Exception('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: financial_transactions.reversed_transaction_id', 19),
        );
    });

    expect(fn () => $transactionService->reverseTransaction(new ReverseFinancialTransactionInput(
        user: $user,
        originalTransactionId: $validTx->id,
        idempotencyKey: 'c1000000-0000-4000-8000-000000000004',
        reason: 'Concurrent race',
    )))->toThrow(LedgerIntegrityException::class, 'This financial transaction has already been reversed.');
});
