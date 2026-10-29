<?php

use App\DataTransferObjects\PostingInstruction;
use App\DataTransferObjects\PostJournalEntryInput;
use App\Enums\CategoryType;
use App\Enums\FinancialTransactionType;
use App\Enums\LedgerAccountClassification;
use App\Exceptions\ImmutableRecordException;
use App\Exceptions\LedgerIntegrityException;
use App\Models\Category;
use App\Models\Currency;
use App\Models\FinancialTransaction;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\FinancialAccountService;
use App\Services\LedgerPostingService;
use App\Support\Money;
use Database\Seeders\CurrencySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CurrencySeeder::class);
});

test('categories can be created with valid income and expense types and reject invalid types', function (): void {
    $user = User::factory()->create();

    $incomeCategory = Category::query()->create([
        'user_id' => $user->id,
        'name' => 'Salary',
        'type' => CategoryType::Income,
        'is_archived' => false,
        'archived_at' => null,
    ]);

    $expenseCategory = Category::query()->create([
        'user_id' => $user->id,
        'name' => 'Groceries',
        'type' => CategoryType::Expense,
        'is_archived' => false,
        'archived_at' => null,
    ]);

    expect($incomeCategory->type)->toBe(CategoryType::Income)
        ->and($incomeCategory->is_archived)->toBeFalse()
        ->and($incomeCategory->user->is($user))->toBeTrue()
        ->and($expenseCategory->type)->toBe(CategoryType::Expense)
        ->and($user->categories)->toHaveCount(2);

    // Invalid category type rejected by database CHECK constraint
    expect(fn () => DB::table('categories')->insert([
        'user_id' => $user->id,
        'name' => 'Invalid Type Category',
        'type' => 'transfer',
        'is_archived' => false,
        'archived_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('category duplicate names per user and type are rejected while same name across different types or users is allowed', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    Category::query()->create([
        'user_id' => $userA->id,
        'name' => 'Consulting',
        'type' => CategoryType::Income,
        'is_archived' => false,
        'archived_at' => null,
    ]);

    // Same name and type for same user is rejected
    expect(fn () => Category::query()->create([
        'user_id' => $userA->id,
        'name' => 'Consulting',
        'type' => CategoryType::Income,
        'is_archived' => false,
        'archived_at' => null,
    ]))->toThrow(QueryException::class);

    // Same name under Expense type for same user is allowed
    $expenseConsulting = Category::query()->create([
        'user_id' => $userA->id,
        'name' => 'Consulting',
        'type' => CategoryType::Expense,
        'is_archived' => false,
        'archived_at' => null,
    ]);
    expect($expenseConsulting->exists)->toBeTrue();

    // Same name and type for another user is allowed
    $otherUserConsulting = Category::query()->create([
        'user_id' => $userB->id,
        'name' => 'Consulting',
        'type' => CategoryType::Income,
        'is_archived' => false,
        'archived_at' => null,
    ]);
    expect($otherUserConsulting->exists)->toBeTrue();
});

test('category ownership and type are immutable at both Eloquent and raw SQL levels, while renaming, archive, and restore remain allowed', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $category = Category::query()->create([
        'user_id' => $user->id,
        'name' => 'Utilities',
        'type' => CategoryType::Expense,
        'is_archived' => false,
        'archived_at' => null,
    ]);

    // 1. Eloquent cannot change user_id or type
    expect(fn () => $category->update(['user_id' => $otherUser->id]))
        ->toThrow(ImmutableRecordException::class, 'Category ownership cannot be modified.');

    $category->refresh();
    expect(fn () => $category->update(['type' => CategoryType::Income]))
        ->toThrow(ImmutableRecordException::class, 'Category type cannot be changed after creation.');

    // 2. Raw SQL cannot change user_id or type (rejected by trg_categories_validate_update)
    expect(fn () => DB::table('categories')->where('id', $category->id)->update(['user_id' => $otherUser->id]))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('categories')->where('id', $category->id)->update(['type' => 'income']))
        ->toThrow(QueryException::class);

    // 3. Category renaming, archiving, and restoring remain possible via Eloquent and raw SQL
    $category->refresh();
    $category->update([
        'name' => 'Home Utilities',
        'is_archived' => true,
        'archived_at' => now(),
    ]);
    expect($category->fresh()?->name)->toBe('Home Utilities')
        ->and($category->fresh()?->is_archived)->toBeTrue()
        ->and($category->fresh()?->archived_at)->not->toBeNull();

    // Restore back to active
    $category->update([
        'is_archived' => false,
        'archived_at' => null,
    ]);
    expect($category->fresh()?->is_archived)->toBeFalse()
        ->and($category->fresh()?->archived_at)->toBeNull();

    // Raw SQL rename and archive/restore also succeed when user_id and type are unchanged
    DB::table('categories')->where('id', $category->id)->update([
        'name' => 'Household Utilities',
        'is_archived' => 1,
        'archived_at' => now(),
        'updated_at' => now(),
    ]);
    expect($category->fresh()?->name)->toBe('Household Utilities')
        ->and($category->fresh()?->is_archived)->toBeTrue();

    DB::table('categories')->where('id', $category->id)->update([
        'is_archived' => 0,
        'archived_at' => null,
        'updated_at' => now(),
    ]);
    expect($category->fresh()?->is_archived)->toBeFalse()
        ->and($category->fresh()?->archived_at)->toBeNull();

    // 4. Deletion is blocked
    expect(fn () => $category->delete())
        ->toThrow(LedgerIntegrityException::class, 'Categories cannot be deleted.');

    // 5. Inconsistent archive state rejected at model level
    expect(fn () => Category::query()->create([
        'user_id' => $user->id,
        'name' => 'Inconsistent Active',
        'type' => CategoryType::Expense,
        'is_archived' => false,
        'archived_at' => now(),
    ]))->toThrow(LedgerIntegrityException::class, 'archive state must match archived_at');

    expect(fn () => Category::query()->create([
        'user_id' => $user->id,
        'name' => 'Inconsistent Archived',
        'type' => CategoryType::Expense,
        'is_archived' => true,
        'archived_at' => null,
    ]))->toThrow(LedgerIntegrityException::class, 'archive state must match archived_at');

    // 6. Inconsistent archive state rejected at database CHECK constraint level
    expect(fn () => DB::table('categories')->insert([
        'user_id' => $user->id,
        'name' => 'Raw Inconsistent 1',
        'type' => 'expense',
        'is_archived' => 1,
        'archived_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('categories')->insert([
        'user_id' => $user->id,
        'name' => 'Raw Inconsistent 2',
        'type' => 'expense',
        'is_archived' => 0,
        'archived_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('category-backed ledger accounts enforce classification matching, mutual exclusivity with financial accounts, one per currency, and immutable category_id', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $accountService = app(FinancialAccountService::class);

    $checking = $accountService->createAccount($user, [
        'name' => 'Primary Checking',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);

    $groceries = Category::query()->create([
        'user_id' => $user->id,
        'name' => 'Groceries',
        'type' => CategoryType::Expense,
        'is_archived' => false,
        'archived_at' => null,
    ]);

    $salary = Category::query()->create([
        'user_id' => $user->id,
        'name' => 'Salary',
        'type' => CategoryType::Income,
        'is_archived' => false,
        'archived_at' => null,
    ]);

    // Valid PHP and USD ledger accounts for the same Groceries category
    $groceriesPhpLedger = LedgerAccount::query()->create([
        'user_id' => $user->id,
        'category_id' => $groceries->id,
        'code' => "CAT-{$groceries->id}-PHP",
        'name' => 'Groceries (PHP)',
        'classification' => LedgerAccountClassification::Expense,
        'currency_code' => 'PHP',
        'is_system' => false,
    ]);

    $groceriesUsdLedger = LedgerAccount::query()->create([
        'user_id' => $user->id,
        'category_id' => $groceries->id,
        'code' => "CAT-{$groceries->id}-USD",
        'name' => 'Groceries (USD)',
        'classification' => LedgerAccountClassification::Expense,
        'currency_code' => 'USD',
        'is_system' => false,
    ]);

    expect($groceries->ledgerAccounts)->toHaveCount(2)
        ->and($groceriesPhpLedger->category->is($groceries))->toBeTrue()
        ->and($groceriesUsdLedger->category->is($groceries))->toBeTrue();

    // Duplicate (category_id, currency_code) is rejected by unique index
    expect(fn () => LedgerAccount::query()->create([
        'user_id' => $user->id,
        'category_id' => $groceries->id,
        'code' => "CAT-{$groceries->id}-PHP-DUP",
        'name' => 'Groceries Duplicate PHP',
        'classification' => LedgerAccountClassification::Expense,
        'currency_code' => 'PHP',
        'is_system' => false,
    ]))->toThrow(QueryException::class);

    // Classification mismatch (Expense category with Income ledger classification) is rejected at model and DB trigger levels
    expect(fn () => LedgerAccount::query()->create([
        'user_id' => $user->id,
        'category_id' => $groceries->id,
        'code' => 'CAT-MISMATCH-1',
        'name' => 'Mismatched Groceries',
        'classification' => LedgerAccountClassification::Income,
        'currency_code' => 'EUR',
        'is_system' => false,
    ]))->toThrow(LedgerIntegrityException::class, 'Ledger account classification must match its linked category type.');

    expect(fn () => DB::table('ledger_accounts')->insert([
        'user_id' => $user->id,
        'financial_account_id' => null,
        'category_id' => $groceries->id,
        'code' => 'RAW-CAT-MISMATCH',
        'name' => 'Raw Mismatched Groceries',
        'classification' => LedgerAccountClassification::Income->value,
        'currency_code' => 'EUR',
        'is_system' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // Mutual exclusivity: linking both financial_account_id and category_id is rejected at model and DB trigger levels
    $secondChecking = $accountService->createAccount($user, [
        'name' => 'Secondary Checking',
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);

    expect(fn () => LedgerAccount::query()->create([
        'user_id' => $user->id,
        'financial_account_id' => $secondChecking->id,
        'category_id' => $groceries->id,
        'code' => 'BOTH-PARENTS-1',
        'name' => 'Both Parents',
        'classification' => LedgerAccountClassification::Asset,
        'currency_code' => 'USD',
        'is_system' => false,
    ]))->toThrow(LedgerIntegrityException::class, 'cannot be linked to both a financial account and a category');

    // Cross-user category mapping is rejected by composite foreign key (category_id, user_id)
    expect(fn () => DB::table('ledger_accounts')->insert([
        'user_id' => $otherUser->id,
        'financial_account_id' => null,
        'category_id' => $groceries->id,
        'code' => 'CROSS-USER-CAT',
        'name' => 'Cross User Category Ledger',
        'classification' => LedgerAccountClassification::Expense->value,
        'currency_code' => 'PHP',
        'is_system' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // Changing category_id after creation is blocked at both Eloquent and DB trigger levels
    expect(fn () => $groceriesPhpLedger->update(['category_id' => $salary->id]))
        ->toThrow(ImmutableRecordException::class, 'Ledger account ownership and mapping cannot be modified.');

    expect(fn () => DB::table('ledger_accounts')->where('id', $groceriesPhpLedger->id)->update(['category_id' => null]))
        ->toThrow(QueryException::class);
});

test('financial transactions support income, expense, and transfer relationships, casts, and preserve references when categories or accounts are archived', function (): void {
    $user = User::factory()->create();
    $accountService = app(FinancialAccountService::class);
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

    $salaryCategory = Category::query()->create([
        'user_id' => $user->id,
        'name' => 'Salary',
        'type' => CategoryType::Income,
        'is_archived' => false,
        'archived_at' => null,
    ]);
    $salaryLedger = LedgerAccount::query()->create([
        'user_id' => $user->id,
        'category_id' => $salaryCategory->id,
        'code' => "CAT-{$salaryCategory->id}-PHP",
        'name' => 'Salary (PHP)',
        'classification' => LedgerAccountClassification::Income,
        'currency_code' => 'PHP',
        'is_system' => false,
    ]);

    $groceriesCategory = Category::query()->create([
        'user_id' => $user->id,
        'name' => 'Groceries',
        'type' => CategoryType::Expense,
        'is_archived' => false,
        'archived_at' => null,
    ]);
    $groceriesLedger = LedgerAccount::query()->create([
        'user_id' => $user->id,
        'category_id' => $groceriesCategory->id,
        'code' => "CAT-{$groceriesCategory->id}-PHP",
        'name' => 'Groceries (PHP)',
        'classification' => LedgerAccountClassification::Expense,
        'currency_code' => 'PHP',
        'is_system' => false,
    ]);

    // 1. Income transaction
    $incomeJournal = $postingService->post(new PostJournalEntryInput(
        user: $user,
        currencyCode: 'PHP',
        entryDate: '2026-10-25',
        description: 'October Salary',
        idempotencyKey: 'tx-income-1',
        postings: [
            new PostingInstruction(ledgerAccountId: $checking->ledgerAccount->id, debitAmountMinor: 5000000),
            new PostingInstruction(ledgerAccountId: $salaryLedger->id, creditAmountMinor: 5000000),
        ],
    ))->journalEntry;

    $incomeTx = FinancialTransaction::query()->create([
        'user_id' => $user->id,
        'journal_entry_id' => $incomeJournal->id,
        'financial_account_id' => $checking->id,
        'transfer_financial_account_id' => null,
        'category_id' => $salaryCategory->id,
        'type' => FinancialTransactionType::Income,
        'currency_code' => 'PHP',
        'amount_minor' => 5000000,
        'entry_date' => '2026-10-25',
        'description' => 'October Salary',
        'notes' => 'Direct deposit',
    ]);

    // 2. Expense transaction
    $expenseJournal = $postingService->post(new PostJournalEntryInput(
        user: $user,
        currencyCode: 'PHP',
        entryDate: '2026-10-26',
        description: 'Weekly groceries',
        idempotencyKey: 'tx-expense-1',
        postings: [
            new PostingInstruction(ledgerAccountId: $groceriesLedger->id, debitAmountMinor: 350000),
            new PostingInstruction(ledgerAccountId: $checking->ledgerAccount->id, creditAmountMinor: 350000),
        ],
    ))->journalEntry;

    $expenseTx = FinancialTransaction::query()->create([
        'user_id' => $user->id,
        'journal_entry_id' => $expenseJournal->id,
        'financial_account_id' => $checking->id,
        'transfer_financial_account_id' => null,
        'category_id' => $groceriesCategory->id,
        'type' => FinancialTransactionType::Expense,
        'currency_code' => 'PHP',
        'amount_minor' => 350000,
        'entry_date' => '2026-10-26',
        'description' => 'Weekly groceries',
        'notes' => null,
    ]);

    // 3. Transfer transaction
    $transferJournal = $postingService->post(new PostJournalEntryInput(
        user: $user,
        currencyCode: 'PHP',
        entryDate: '2026-10-27',
        description: 'Move to savings',
        idempotencyKey: 'tx-transfer-1',
        postings: [
            new PostingInstruction(ledgerAccountId: $savings->ledgerAccount->id, debitAmountMinor: 1000000),
            new PostingInstruction(ledgerAccountId: $checking->ledgerAccount->id, creditAmountMinor: 1000000),
        ],
    ))->journalEntry;

    $transferTx = FinancialTransaction::query()->create([
        'user_id' => $user->id,
        'journal_entry_id' => $transferJournal->id,
        'financial_account_id' => $checking->id,
        'transfer_financial_account_id' => $savings->id,
        'category_id' => null,
        'type' => FinancialTransactionType::Transfer,
        'currency_code' => 'PHP',
        'amount_minor' => 1000000,
        'entry_date' => '2026-10-27',
        'description' => 'Move to savings',
        'notes' => 'Monthly savings goal',
    ]);

    expect($incomeTx->type)->toBe(FinancialTransactionType::Income)
        ->and($incomeTx->amount_minor)->toBe(5000000)
        ->and($incomeTx->entry_date->format('Y-m-d'))->toBe('2026-10-25')
        ->and($incomeTx->journalEntry->is($incomeJournal))->toBeTrue()
        ->and($incomeJournal->financialTransaction->is($incomeTx))->toBeTrue()
        ->and($incomeTx->financialAccount->is($checking))->toBeTrue()
        ->and($incomeTx->category->is($salaryCategory))->toBeTrue()
        ->and($incomeTx->currency->code)->toBe('PHP')
        ->and($transferTx->transferFinancialAccount->is($savings))->toBeTrue()
        ->and($checking->primaryTransactions)->toHaveCount(3)
        ->and($savings->incomingTransfers)->toHaveCount(1)
        ->and($user->financialTransactions)->toHaveCount(3)
        ->and(Currency::find('PHP')?->financialTransactions)->toHaveCount(3);

    // Archiving, renaming, and restoring the category and account preserves ledger account and historical transaction references
    $groceriesCategory->update([
        'name' => 'Supermarket & Groceries',
        'is_archived' => true,
        'archived_at' => now(),
    ]);
    $accountService->archiveAccount($user, $savings);

    $expenseTx->refresh();
    $transferTx->refresh();
    $groceriesLedger->refresh();

    expect($expenseTx->category->name)->toBe('Supermarket & Groceries')
        ->and($expenseTx->category->is_archived)->toBeTrue()
        ->and($groceriesLedger->category->is($groceriesCategory))->toBeTrue()
        ->and($groceriesCategory->financialTransactions)->toHaveCount(1)
        ->and($groceriesCategory->ledgerAccounts)->toHaveCount(1)
        ->and($transferTx->transferFinancialAccount->name)->toBe('Savings PHP')
        ->and($transferTx->transferFinancialAccount->is_archived)->toBeTrue();

    $groceriesCategory->update([
        'is_archived' => false,
        'archived_at' => null,
    ]);
    expect($expenseTx->fresh()?->category->is_archived)->toBeFalse()
        ->and($groceriesLedger->fresh()?->category->is_archived)->toBeFalse();
});

test('financial transactions enforce amount bounds, type rules, account distinctness, tenant isolation, currency correspondence, and immutability', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $accountService = app(FinancialAccountService::class);
    $postingService = app(LedgerPostingService::class);

    $checkingPhp = $accountService->createAccount($userA, [
        'name' => 'Checking PHP',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);
    $savingsPhp = $accountService->createAccount($userA, [
        'name' => 'Savings PHP',
        'type' => 'savings',
        'currency_code' => 'PHP',
    ]);
    $checkingUsd = $accountService->createAccount($userA, [
        'name' => 'Checking USD',
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);
    $userBCheckingPhp = $accountService->createAccount($userB, [
        'name' => 'User B Checking PHP',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);

    $incomeCategory = Category::query()->create([
        'user_id' => $userA->id,
        'name' => 'Salary',
        'type' => CategoryType::Income,
        'is_archived' => false,
        'archived_at' => null,
    ]);
    $expenseCategory = Category::query()->create([
        'user_id' => $userA->id,
        'name' => 'Rent',
        'type' => CategoryType::Expense,
        'is_archived' => false,
        'archived_at' => null,
    ]);
    $userBCategory = Category::query()->create([
        'user_id' => $userB->id,
        'name' => 'User B Expense',
        'type' => CategoryType::Expense,
        'is_archived' => false,
        'archived_at' => null,
    ]);

    $makePhpJournal = function (string $key, int $amount = 10000) use ($userA, $checkingPhp, $savingsPhp, $postingService) {
        return $postingService->post(new PostJournalEntryInput(
            user: $userA,
            currencyCode: 'PHP',
            entryDate: '2026-10-27',
            description: "Journal {$key}",
            idempotencyKey: $key,
            postings: [
                new PostingInstruction(ledgerAccountId: $savingsPhp->ledgerAccount->id, debitAmountMinor: $amount),
                new PostingInstruction(ledgerAccountId: $checkingPhp->ledgerAccount->id, creditAmountMinor: $amount),
            ],
        ))->journalEntry;
    };

    $j1 = $makePhpJournal('j1');

    // 1. Zero, negative, or > MAX_MINOR_AMOUNT rejected by database CHECK constraint
    expect(fn () => DB::table('financial_transactions')->insert([
        'user_id' => $userA->id,
        'journal_entry_id' => $j1->id,
        'financial_account_id' => $checkingPhp->id,
        'transfer_financial_account_id' => null,
        'category_id' => $expenseCategory->id,
        'type' => 'expense',
        'currency_code' => 'PHP',
        'amount_minor' => 0,
        'entry_date' => '2026-10-27',
        'description' => 'Zero amount',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('financial_transactions')->insert([
        'user_id' => $userA->id,
        'journal_entry_id' => $j1->id,
        'financial_account_id' => $checkingPhp->id,
        'transfer_financial_account_id' => null,
        'category_id' => $expenseCategory->id,
        'type' => 'expense',
        'currency_code' => 'PHP',
        'amount_minor' => -500,
        'entry_date' => '2026-10-27',
        'description' => 'Negative amount',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('financial_transactions')->insert([
        'user_id' => $userA->id,
        'journal_entry_id' => $j1->id,
        'financial_account_id' => $checkingPhp->id,
        'transfer_financial_account_id' => null,
        'category_id' => $expenseCategory->id,
        'type' => 'expense',
        'currency_code' => 'PHP',
        'amount_minor' => Money::MAX_MINOR_AMOUNT + 1,
        'entry_date' => '2026-10-27',
        'description' => 'Overflow amount',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // 2. Invalid transaction type rejected
    expect(fn () => DB::table('financial_transactions')->insert([
        'user_id' => $userA->id,
        'journal_entry_id' => $j1->id,
        'financial_account_id' => $checkingPhp->id,
        'transfer_financial_account_id' => null,
        'category_id' => $expenseCategory->id,
        'type' => 'adjustment',
        'currency_code' => 'PHP',
        'amount_minor' => 10000,
        'entry_date' => '2026-10-27',
        'description' => 'Invalid type',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // 3. Income/Expense without category_id or with transfer_financial_account_id rejected
    expect(fn () => DB::table('financial_transactions')->insert([
        'user_id' => $userA->id,
        'journal_entry_id' => $j1->id,
        'financial_account_id' => $checkingPhp->id,
        'transfer_financial_account_id' => null,
        'category_id' => null,
        'type' => 'expense',
        'currency_code' => 'PHP',
        'amount_minor' => 10000,
        'entry_date' => '2026-10-27',
        'description' => 'Expense missing category',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('financial_transactions')->insert([
        'user_id' => $userA->id,
        'journal_entry_id' => $j1->id,
        'financial_account_id' => $checkingPhp->id,
        'transfer_financial_account_id' => $savingsPhp->id,
        'category_id' => $expenseCategory->id,
        'type' => 'expense',
        'currency_code' => 'PHP',
        'amount_minor' => 10000,
        'entry_date' => '2026-10-27',
        'description' => 'Expense with transfer account',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // 4. Transfer with category_id, missing transfer_financial_account_id, or identical source/destination account rejected
    expect(fn () => DB::table('financial_transactions')->insert([
        'user_id' => $userA->id,
        'journal_entry_id' => $j1->id,
        'financial_account_id' => $checkingPhp->id,
        'transfer_financial_account_id' => $savingsPhp->id,
        'category_id' => $expenseCategory->id,
        'type' => 'transfer',
        'currency_code' => 'PHP',
        'amount_minor' => 10000,
        'entry_date' => '2026-10-27',
        'description' => 'Transfer with category',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('financial_transactions')->insert([
        'user_id' => $userA->id,
        'journal_entry_id' => $j1->id,
        'financial_account_id' => $checkingPhp->id,
        'transfer_financial_account_id' => $checkingPhp->id,
        'category_id' => null,
        'type' => 'transfer',
        'currency_code' => 'PHP',
        'amount_minor' => 10000,
        'entry_date' => '2026-10-27',
        'description' => 'Self transfer',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // 5. Category type mismatch (Income transaction with Expense category) rejected by BEFORE INSERT trigger
    expect(fn () => DB::table('financial_transactions')->insert([
        'user_id' => $userA->id,
        'journal_entry_id' => $j1->id,
        'financial_account_id' => $checkingPhp->id,
        'transfer_financial_account_id' => null,
        'category_id' => $expenseCategory->id,
        'type' => 'income',
        'currency_code' => 'PHP',
        'amount_minor' => 10000,
        'entry_date' => '2026-10-27',
        'description' => 'Income with expense category',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // 6. Currency mismatch with journal entry or accounts rejected by composite FK / trigger
    expect(fn () => DB::table('financial_transactions')->insert([
        'user_id' => $userA->id,
        'journal_entry_id' => $j1->id,
        'financial_account_id' => $checkingPhp->id,
        'transfer_financial_account_id' => null,
        'category_id' => $expenseCategory->id,
        'type' => 'expense',
        'currency_code' => 'USD',
        'amount_minor' => 10000,
        'entry_date' => '2026-10-27',
        'description' => 'Mismatched journal currency',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('financial_transactions')->insert([
        'user_id' => $userA->id,
        'journal_entry_id' => $j1->id,
        'financial_account_id' => $checkingPhp->id,
        'transfer_financial_account_id' => $checkingUsd->id,
        'category_id' => null,
        'type' => 'transfer',
        'currency_code' => 'PHP',
        'amount_minor' => 10000,
        'entry_date' => '2026-10-27',
        'description' => 'Mismatched transfer account currency',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // 7. Cross-user account or category rejected by composite foreign keys
    expect(fn () => DB::table('financial_transactions')->insert([
        'user_id' => $userA->id,
        'journal_entry_id' => $j1->id,
        'financial_account_id' => $userBCheckingPhp->id,
        'transfer_financial_account_id' => null,
        'category_id' => $expenseCategory->id,
        'type' => 'expense',
        'currency_code' => 'PHP',
        'amount_minor' => 10000,
        'entry_date' => '2026-10-27',
        'description' => 'Cross user account',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('financial_transactions')->insert([
        'user_id' => $userA->id,
        'journal_entry_id' => $j1->id,
        'financial_account_id' => $checkingPhp->id,
        'transfer_financial_account_id' => null,
        'category_id' => $userBCategory->id,
        'type' => 'expense',
        'currency_code' => 'PHP',
        'amount_minor' => 10000,
        'entry_date' => '2026-10-27',
        'description' => 'Cross user category',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // 8. Valid transaction creation, unique journal_entry_id enforcement, and model + raw SQL immutability
    $validTx = FinancialTransaction::query()->create([
        'user_id' => $userA->id,
        'journal_entry_id' => $j1->id,
        'financial_account_id' => $checkingPhp->id,
        'transfer_financial_account_id' => null,
        'category_id' => $incomeCategory->id,
        'type' => FinancialTransactionType::Income,
        'currency_code' => 'PHP',
        'amount_minor' => 10000,
        'entry_date' => '2026-10-27',
        'description' => 'Valid income',
    ]);

    // Reusing the same journal_entry_id is rejected
    expect(fn () => FinancialTransaction::query()->create([
        'user_id' => $userA->id,
        'journal_entry_id' => $j1->id,
        'financial_account_id' => $checkingPhp->id,
        'transfer_financial_account_id' => null,
        'category_id' => $incomeCategory->id,
        'type' => FinancialTransactionType::Income,
        'currency_code' => 'PHP',
        'amount_minor' => 10000,
        'entry_date' => '2026-10-27',
        'description' => 'Duplicate journal link',
    ]))->toThrow(QueryException::class);

    // Eloquent model immutability
    expect(fn () => $validTx->update(['description' => 'Tampered']))
        ->toThrow(ImmutableRecordException::class);

    expect(fn () => $validTx->delete())
        ->toThrow(ImmutableRecordException::class);

    // Raw SQL immutability triggers
    expect(fn () => DB::table('financial_transactions')->where('id', $validTx->id)->update(['amount_minor' => 99999]))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('financial_transactions')->where('id', $validTx->id)->delete())
        ->toThrow(QueryException::class);
});

test('categories, ledger category column, and financial transactions migrations roll back cleanly and re-migrate', function (): void {
    $migrationFiles = [
        base_path('database/migrations/2026_10_27_000010_add_reversal_columns_to_financial_transactions_table.php'),
        base_path('database/migrations/2026_10_27_000009_create_financial_transactions_table.php'),
        base_path('database/migrations/2026_10_27_000008_add_category_id_to_ledger_accounts_table.php'),
        base_path('database/migrations/2026_10_27_000007_create_categories_table.php'),
    ];

    foreach ($migrationFiles as $file) {
        $migration = require $file;
        $migration->down();
    }

    expect(Schema::hasTable('financial_transactions'))->toBeFalse()
        ->and(Schema::hasColumn('ledger_accounts', 'category_id'))->toBeFalse()
        ->and(Schema::hasTable('categories'))->toBeFalse();

    foreach (array_reverse($migrationFiles) as $file) {
        $migration = require $file;
        $migration->up();
    }

    expect(Schema::hasTable('categories'))->toBeTrue()
        ->and(Schema::hasColumn('ledger_accounts', 'category_id'))->toBeTrue()
        ->and(Schema::hasTable('financial_transactions'))->toBeTrue()
        ->and(Schema::hasColumn('financial_transactions', 'reversed_transaction_id'))->toBeTrue()
        ->and(Schema::hasColumn('financial_transactions', 'reversal_reason'))->toBeTrue();
});

test('financial transaction reversal schema constraints prevent duplicate reversals, reversal of reversal, mismatched attributes, and orphan reversal reasons', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $accountService = app(FinancialAccountService::class);
    $postingService = app(LedgerPostingService::class);

    $checkingPhp = $accountService->createAccount($userA, [
        'name' => 'Checking PHP',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);
    $savingsPhp = $accountService->createAccount($userA, [
        'name' => 'Savings PHP',
        'type' => 'savings',
        'currency_code' => 'PHP',
    ]);
    $userBCheckingPhp = $accountService->createAccount($userB, [
        'name' => 'User B Checking PHP',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);
    $userBSavingsPhp = $accountService->createAccount($userB, [
        'name' => 'User B Savings PHP',
        'type' => 'savings',
        'currency_code' => 'PHP',
    ]);

    $expenseCategory = Category::query()->create([
        'user_id' => $userA->id,
        'name' => 'Dining',
        'type' => CategoryType::Expense,
        'is_archived' => false,
        'archived_at' => null,
    ]);
    $secondExpenseCategory = Category::query()->create([
        'user_id' => $userA->id,
        'name' => 'Travel',
        'type' => CategoryType::Expense,
        'is_archived' => false,
        'archived_at' => null,
    ]);
    $userBCategory = Category::query()->create([
        'user_id' => $userB->id,
        'name' => 'User B Dining',
        'type' => CategoryType::Expense,
        'is_archived' => false,
        'archived_at' => null,
    ]);

    $makeJournal = function (User $owner, int $ledgerA, int $ledgerB, string $key, int $amount = 25000) use ($postingService) {
        return $postingService->post(new PostJournalEntryInput(
            user: $owner,
            currencyCode: 'PHP',
            entryDate: '2026-10-28',
            description: "Journal {$key}",
            idempotencyKey: $key,
            postings: [
                new PostingInstruction(ledgerAccountId: $ledgerA, debitAmountMinor: $amount),
                new PostingInstruction(ledgerAccountId: $ledgerB, creditAmountMinor: $amount),
            ],
        ))->journalEntry;
    };

    $jOrig = $makeJournal($userA, $savingsPhp->ledgerAccount->id, $checkingPhp->ledgerAccount->id, 'rev-schema-orig');
    $jRev1 = $makeJournal($userA, $checkingPhp->ledgerAccount->id, $savingsPhp->ledgerAccount->id, 'rev-schema-1');
    $jRev2 = $makeJournal($userA, $checkingPhp->ledgerAccount->id, $savingsPhp->ledgerAccount->id, 'rev-schema-2');
    $jUserB = $makeJournal($userB, $userBCheckingPhp->ledgerAccount->id, $userBSavingsPhp->ledgerAccount->id, 'rev-schema-b', 25000);

    $origTx = FinancialTransaction::query()->create([
        'user_id' => $userA->id,
        'journal_entry_id' => $jOrig->id,
        'financial_account_id' => $checkingPhp->id,
        'transfer_financial_account_id' => null,
        'category_id' => $expenseCategory->id,
        'type' => FinancialTransactionType::Expense,
        'currency_code' => 'PHP',
        'amount_minor' => 25000,
        'entry_date' => '2026-10-28',
        'description' => 'Dinner',
    ]);

    // 1. Non-reversal transaction with reversal_reason is rejected at model and DB CHECK levels
    expect(fn () => FinancialTransaction::query()->create([
        'user_id' => $userA->id,
        'journal_entry_id' => $jRev1->id,
        'reversed_transaction_id' => null,
        'financial_account_id' => $checkingPhp->id,
        'category_id' => $expenseCategory->id,
        'type' => FinancialTransactionType::Expense,
        'currency_code' => 'PHP',
        'amount_minor' => 25000,
        'entry_date' => '2026-10-28',
        'description' => 'Orphan reason',
        'reversal_reason' => 'Should fail',
    ]))->toThrow(LedgerIntegrityException::class, 'Non-reversal transactions cannot specify a reversal reason.');

    expect(fn () => DB::table('financial_transactions')->insert([
        'user_id' => $userA->id,
        'journal_entry_id' => $jRev1->id,
        'reversed_transaction_id' => null,
        'financial_account_id' => $checkingPhp->id,
        'transfer_financial_account_id' => null,
        'category_id' => $expenseCategory->id,
        'type' => 'expense',
        'currency_code' => 'PHP',
        'amount_minor' => 25000,
        'entry_date' => '2026-10-28',
        'description' => 'Raw orphan reason',
        'reversal_reason' => 'Should fail at DB check',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // 2. Reversal with mismatched amount_minor or category_id is rejected by BEFORE INSERT trigger
    expect(fn () => DB::table('financial_transactions')->insert([
        'user_id' => $userA->id,
        'journal_entry_id' => $jRev1->id,
        'reversed_transaction_id' => $origTx->id,
        'financial_account_id' => $checkingPhp->id,
        'transfer_financial_account_id' => null,
        'category_id' => $expenseCategory->id,
        'type' => 'expense',
        'currency_code' => 'PHP',
        'amount_minor' => 99999,
        'entry_date' => '2026-10-28',
        'description' => 'Mismatched reversal amount',
        'reversal_reason' => 'Wrong amount',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('financial_transactions')->insert([
        'user_id' => $userA->id,
        'journal_entry_id' => $jRev1->id,
        'reversed_transaction_id' => $origTx->id,
        'financial_account_id' => $checkingPhp->id,
        'transfer_financial_account_id' => null,
        'category_id' => $secondExpenseCategory->id,
        'type' => 'expense',
        'currency_code' => 'PHP',
        'amount_minor' => 25000,
        'entry_date' => '2026-10-28',
        'description' => 'Mismatched reversal category',
        'reversal_reason' => 'Wrong category',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // 3. Cross-user reversal link is rejected by composite foreign key & trigger
    expect(fn () => DB::table('financial_transactions')->insert([
        'user_id' => $userB->id,
        'journal_entry_id' => $jUserB->id,
        'reversed_transaction_id' => $origTx->id,
        'financial_account_id' => $userBCheckingPhp->id,
        'transfer_financial_account_id' => null,
        'category_id' => $userBCategory->id,
        'type' => 'expense',
        'currency_code' => 'PHP',
        'amount_minor' => 25000,
        'entry_date' => '2026-10-28',
        'description' => 'Cross-user reversal',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // 4. Valid reversal succeeds and links both directions
    $validReversal = FinancialTransaction::query()->create([
        'user_id' => $userA->id,
        'journal_entry_id' => $jRev1->id,
        'reversed_transaction_id' => $origTx->id,
        'financial_account_id' => $checkingPhp->id,
        'transfer_financial_account_id' => null,
        'category_id' => $expenseCategory->id,
        'type' => FinancialTransactionType::Expense,
        'currency_code' => 'PHP',
        'amount_minor' => 25000,
        'entry_date' => '2026-10-28',
        'description' => 'Reversal: Dinner',
        'reversal_reason' => 'Duplicate entry',
    ]);

    expect($validReversal->isReversal())->toBeTrue()
        ->and($validReversal->isReversed())->toBeFalse()
        ->and($origTx->fresh()?->isReversal())->toBeFalse()
        ->and($origTx->fresh()?->isReversed())->toBeTrue()
        ->and($validReversal->reversedTransaction->is($origTx))->toBeTrue()
        ->and($origTx->reversalTransaction->is($validReversal))->toBeTrue();

    // 5. Second reversal of the same original transaction is rejected by UNIQUE constraint
    expect(fn () => DB::table('financial_transactions')->insert([
        'user_id' => $userA->id,
        'journal_entry_id' => $jRev2->id,
        'reversed_transaction_id' => $origTx->id,
        'financial_account_id' => $checkingPhp->id,
        'transfer_financial_account_id' => null,
        'category_id' => $expenseCategory->id,
        'type' => 'expense',
        'currency_code' => 'PHP',
        'amount_minor' => 25000,
        'entry_date' => '2026-10-28',
        'description' => 'Second reversal attempt',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // 6. Reversal of a reversal is rejected by BEFORE INSERT trigger (orig.reversed_transaction_id IS NULL)
    expect(fn () => DB::table('financial_transactions')->insert([
        'user_id' => $userA->id,
        'journal_entry_id' => $jRev2->id,
        'reversed_transaction_id' => $validReversal->id,
        'financial_account_id' => $checkingPhp->id,
        'transfer_financial_account_id' => null,
        'category_id' => $expenseCategory->id,
        'type' => 'expense',
        'currency_code' => 'PHP',
        'amount_minor' => 25000,
        'entry_date' => '2026-10-28',
        'description' => 'Reversal of reversal',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // 7. Self-referencing reversal is rejected (both explicit id = reversed_transaction_id and predicted auto-increment id)
    expect(fn () => DB::table('financial_transactions')->insert([
        'id' => 9999,
        'user_id' => $userA->id,
        'journal_entry_id' => $jRev2->id,
        'reversed_transaction_id' => 9999,
        'financial_account_id' => $checkingPhp->id,
        'transfer_financial_account_id' => null,
        'category_id' => $expenseCategory->id,
        'type' => 'expense',
        'currency_code' => 'PHP',
        'amount_minor' => 25000,
        'entry_date' => '2026-10-28',
        'description' => 'Explicit self-reversal',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    $nextPredictedId = ((int) DB::table('financial_transactions')->max('id')) + 1;
    expect(fn () => DB::table('financial_transactions')->insert([
        'user_id' => $userA->id,
        'journal_entry_id' => $jRev2->id,
        'reversed_transaction_id' => $nextPredictedId,
        'financial_account_id' => $checkingPhp->id,
        'transfer_financial_account_id' => null,
        'category_id' => $expenseCategory->id,
        'type' => 'expense',
        'currency_code' => 'PHP',
        'amount_minor' => 25000,
        'entry_date' => '2026-10-28',
        'description' => 'Predicted auto-increment self-reversal',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    $selfRefModel = new FinancialTransaction([
        'user_id' => $userA->id,
        'journal_entry_id' => $jRev2->id,
        'reversed_transaction_id' => 7777,
        'financial_account_id' => $checkingPhp->id,
        'transfer_financial_account_id' => null,
        'category_id' => $expenseCategory->id,
        'type' => FinancialTransactionType::Expense,
        'currency_code' => 'PHP',
        'amount_minor' => 25000,
        'entry_date' => '2026-10-28',
        'description' => 'Model self-reversal',
    ]);
    $selfRefModel->id = 7777;
    expect(fn () => $selfRefModel->save())
        ->toThrow(LedgerIntegrityException::class, 'A financial transaction cannot reverse itself.');
});

test('reversal migration preserves pre-existing transactions across up and down, restores original constraints on safe rollback, refuses rollback when reversals exist, and guards CHECK syntax and drivers', function (): void {
    $migrationFile = base_path('database/migrations/2026_10_27_000010_add_reversal_columns_to_financial_transactions_table.php');
    $migration = require $migrationFile;

    // 1. Malformed CHECK and MariaDB Error 1901 regression guard:
    //    Verify the full evaluated values of BOTH constants, ensure neither references the standalone `id`
    //    AUTO_INCREMENT column, and ensure neither contains "NULLAND".
    $expectedOriginalCheckExpression = 'amount_minor > 0 '
        .'AND amount_minor <= 1000000000000000000 '
        ."AND type IN ('income', 'expense', 'transfer') "
        ."AND ((type IN ('income', 'expense') AND category_id IS NOT NULL AND transfer_financial_account_id IS NULL) "
        ."OR (type = 'transfer' AND category_id IS NULL AND transfer_financial_account_id IS NOT NULL AND transfer_financial_account_id <> financial_account_id))";

    $expectedReversalCheckExpression = $expectedOriginalCheckExpression.' '
        .'AND ((reversed_transaction_id IS NULL AND reversal_reason IS NULL) '
        .'OR (reversed_transaction_id IS NOT NULL AND reversed_transaction_id > 0))';

    $migrationSource = (string) file_get_contents($migrationFile);
    $tableSql = (string) DB::table('sqlite_master')
        ->where('type', 'table')
        ->where('name', 'financial_transactions')
        ->value('sql');

    expect($migration::ORIGINAL_CHECK_EXPRESSION)
        ->toBe($expectedOriginalCheckExpression)
        ->toContain('transfer_financial_account_id IS NOT NULL AND transfer_financial_account_id <> financial_account_id')
        ->not->toContain('NULLAND')
        ->and(preg_match('/\bid\b/i', $migration::ORIGINAL_CHECK_EXPRESSION))
        ->toBe(0)
        ->and($migration::CHECK_EXPRESSION)
        ->toBe($expectedReversalCheckExpression)
        ->toContain('transfer_financial_account_id IS NOT NULL AND transfer_financial_account_id <> financial_account_id')
        ->toContain('reversed_transaction_id IS NOT NULL AND reversed_transaction_id > 0')
        ->not->toContain('NULLAND')
        ->and(preg_match('/\bid\b/i', $migration::CHECK_EXPRESSION))
        ->toBe(0)
        ->and($migrationSource)
        ->not->toContain('NULLAND')
        ->not->toContain('reversed_transaction_id <> id')
        ->and($tableSql)
        ->toContain($expectedReversalCheckExpression)
        ->not->toContain('NULLAND');

    // 2. Unsupported driver rejection guard
    $assertDriverMethod = new ReflectionMethod($migration, 'assertSupportedDriver');
    expect(fn () => $assertDriverMethod->invoke($migration, 'pgsql'))
        ->toThrow(RuntimeException::class, 'Unsupported database driver [pgsql]')
        ->and(fn () => $assertDriverMethod->invoke($migration, 'sqlsrv'))
        ->toThrow(RuntimeException::class, 'Unsupported database driver [sqlsrv]');

    // 3. Safe rollback when no reversal transactions exist restores original schema, indexes, foreign keys, CHECK constraint, and triggers
    $indexesDuringUp = array_column(Schema::getIndexes('financial_transactions'), 'name');
    expect($indexesDuringUp)
        ->toContain('financial_transactions_id_user_id_currency_code_unique')
        ->toContain('financial_transactions_reversed_transaction_id_unique');

    $migration->down();

    expect(Schema::hasColumn('financial_transactions', 'reversed_transaction_id'))->toBeFalse()
        ->and(Schema::hasColumn('financial_transactions', 'reversal_reason'))->toBeFalse();

    $indexesAfterDown = array_column(Schema::getIndexes('financial_transactions'), 'name');
    $foreignKeysAfterDown = array_column(Schema::getForeignKeys('financial_transactions'), 'name');

    expect($indexesAfterDown)
        ->toContain('financial_transactions_journal_entry_id_unique')
        ->toContain('financial_transactions_user_date_id_index')
        ->toContain('financial_transactions_user_account_date_index')
        ->toContain('financial_transactions_user_transfer_account_date_index')
        ->toContain('financial_transactions_user_category_date_index')
        ->not->toContain('financial_transactions_id_user_id_currency_code_unique')
        ->not->toContain('financial_transactions_reversed_transaction_id_unique')
        ->not->toContain('financial_transactions_reversed_tx_tenant_currency_foreign')
        ->and($foreignKeysAfterDown)
        ->not->toContain('financial_transactions_reversed_tx_tenant_currency_foreign')
        ->and($migrationSource)
        ->toContain("\$table->dropForeign('financial_transactions_reversed_tx_tenant_currency_foreign');")
        ->toContain("\$table->dropIndex('financial_transactions_reversed_tx_tenant_currency_foreign');");

    $user = User::factory()->create();
    $accountService = app(FinancialAccountService::class);
    $postingService = app(LedgerPostingService::class);

    $checking = $accountService->createAccount($user, [
        'name' => 'Pre-Migration Checking',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);
    $savings = $accountService->createAccount($user, [
        'name' => 'Pre-Migration Savings',
        'type' => 'savings',
        'currency_code' => 'PHP',
    ]);
    $expenseCategory = Category::query()->create([
        'user_id' => $user->id,
        'name' => 'Utilities',
        'type' => CategoryType::Expense,
        'is_archived' => false,
        'archived_at' => null,
    ]);
    $incomeCategory = Category::query()->create([
        'user_id' => $user->id,
        'name' => 'Salary',
        'type' => CategoryType::Income,
        'is_archived' => false,
        'archived_at' => null,
    ]);

    $jPre1 = $postingService->post(new PostJournalEntryInput(
        user: $user,
        currencyCode: 'PHP',
        entryDate: '2026-10-20',
        description: 'Pre-migration expense',
        idempotencyKey: 'pre-mig-1',
        postings: [
            new PostingInstruction(ledgerAccountId: $savings->ledgerAccount->id, debitAmountMinor: 150000),
            new PostingInstruction(ledgerAccountId: $checking->ledgerAccount->id, creditAmountMinor: 150000),
        ],
    ))->journalEntry;

    $jPre2 = $postingService->post(new PostJournalEntryInput(
        user: $user,
        currencyCode: 'PHP',
        entryDate: '2026-10-21',
        description: 'Pre-migration transfer',
        idempotencyKey: 'pre-mig-2',
        postings: [
            new PostingInstruction(ledgerAccountId: $savings->ledgerAccount->id, debitAmountMinor: 300000),
            new PostingInstruction(ledgerAccountId: $checking->ledgerAccount->id, creditAmountMinor: 300000),
        ],
    ))->journalEntry;

    // Verify original CHECK and BEFORE INSERT trigger are enforced after down()
    expect(fn () => DB::table('financial_transactions')->insert([
        'user_id' => $user->id,
        'journal_entry_id' => $jPre1->id,
        'financial_account_id' => $checking->id,
        'transfer_financial_account_id' => null,
        'category_id' => $expenseCategory->id,
        'type' => 'expense',
        'currency_code' => 'PHP',
        'amount_minor' => 0,
        'entry_date' => '2026-10-20',
        'description' => 'Zero amount rejected after down',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('financial_transactions')->insert([
        'user_id' => $user->id,
        'journal_entry_id' => $jPre1->id,
        'financial_account_id' => $checking->id,
        'transfer_financial_account_id' => null,
        'category_id' => $incomeCategory->id,
        'type' => 'expense',
        'currency_code' => 'PHP',
        'amount_minor' => 150000,
        'entry_date' => '2026-10-20',
        'description' => 'Category mismatch rejected after down',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // Insert pre-existing transactions on the pre-reversal schema
    $preTxId = DB::table('financial_transactions')->insertGetId([
        'user_id' => $user->id,
        'journal_entry_id' => $jPre1->id,
        'financial_account_id' => $checking->id,
        'transfer_financial_account_id' => null,
        'category_id' => $expenseCategory->id,
        'type' => 'expense',
        'currency_code' => 'PHP',
        'amount_minor' => 150000,
        'entry_date' => '2026-10-20',
        'description' => 'Pre-migration expense',
        'notes' => 'Historical note',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Verify original immutability UPDATE and DELETE triggers are active after down()
    expect(fn () => DB::table('financial_transactions')->where('id', $preTxId)->update(['amount_minor' => 100]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('financial_transactions')->where('id', $preTxId)->delete())
        ->toThrow(QueryException::class);

    // 4. Run up() while pre-existing transactions exist in financial_transactions
    $migration->up();

    expect(Schema::hasColumn('financial_transactions', 'reversed_transaction_id'))->toBeTrue()
        ->and(Schema::hasColumn('financial_transactions', 'reversal_reason'))->toBeTrue();

    $survivedTx = FinancialTransaction::findOrFail($preTxId);
    expect($survivedTx->description)->toBe('Pre-migration expense')
        ->and($survivedTx->notes)->toBe('Historical note')
        ->and($survivedTx->amount_minor)->toBe(150000)
        ->and($survivedTx->reversed_transaction_id)->toBeNull()
        ->and($survivedTx->reversal_reason)->toBeNull()
        ->and($survivedTx->isReversal())->toBeFalse()
        ->and($survivedTx->isReversed())->toBeFalse();

    // 5. Create a reversal for the survived transaction, then verify down() refuses rollback without altering schema
    $reversalTx = FinancialTransaction::query()->create([
        'user_id' => $user->id,
        'journal_entry_id' => $jPre2->id,
        'reversed_transaction_id' => $survivedTx->id,
        'financial_account_id' => $checking->id,
        'transfer_financial_account_id' => null,
        'category_id' => $expenseCategory->id,
        'type' => FinancialTransactionType::Expense,
        'currency_code' => 'PHP',
        'amount_minor' => 150000,
        'entry_date' => '2026-10-20',
        'description' => 'Reversal: Pre-migration expense',
        'reversal_reason' => 'Corrected historical entry',
    ]);

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Cannot roll back financial_transactions reversal migration while reversal records exist.');

    // Schema, records, and immutability triggers remain intact after refused rollback
    expect(Schema::hasColumn('financial_transactions', 'reversed_transaction_id'))->toBeTrue()
        ->and(Schema::hasColumn('financial_transactions', 'reversal_reason'))->toBeTrue()
        ->and(FinancialTransaction::query()->count())->toBe(2)
        ->and($reversalTx->fresh()?->reversed_transaction_id)->toBe($survivedTx->id)
        ->and(fn () => DB::table('financial_transactions')->where('id', $reversalTx->id)->delete())
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('financial_transactions')->where('id', $survivedTx->id)->update(['description' => 'Changed']))
        ->toThrow(QueryException::class);
});
