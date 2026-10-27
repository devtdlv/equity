<?php

use App\DataTransferObjects\PostingInstruction;
use App\DataTransferObjects\PostJournalEntryInput;
use App\Enums\JournalEntryStatus;
use App\Enums\LedgerAccountClassification;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\ImmutableRecordException;
use App\Exceptions\LedgerIntegrityException;
use App\Models\AuditEvent;
use App\Models\Currency;
use App\Models\JournalEntry;
use App\Models\JournalPosting;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\AccountBalanceService;
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

test('balanced journal entries are posted atomically and derive accurate asset and liability balances', function (): void {
    $user = User::factory()->create();
    $accountService = app(FinancialAccountService::class);
    $postingService = app(LedgerPostingService::class);
    $balanceService = app(AccountBalanceService::class);

    $checking = $accountService->createAccount($user, [
        'name' => 'Main Checking',
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);

    $creditCard = $accountService->createAccount($user, [
        'name' => 'Everyday Card',
        'type' => 'credit_card',
        'currency_code' => 'USD',
    ]);

    $salaryIncome = LedgerAccount::query()->create([
        'user_id' => $user->id,
        'code' => 'INC-SALARY-USD',
        'name' => 'Salary Income',
        'classification' => LedgerAccountClassification::Income,
        'currency_code' => 'USD',
        'is_system' => true,
    ]);

    $groceriesExpense = LedgerAccount::query()->create([
        'user_id' => $user->id,
        'code' => 'EXP-GROCERIES-USD',
        'name' => 'Groceries Expense',
        'classification' => LedgerAccountClassification::Expense,
        'currency_code' => 'USD',
        'is_system' => true,
    ]);

    // 1. Receive $2,500.00 salary into Main Checking
    $salaryResult = $postingService->post(new PostJournalEntryInput(
        user: $user,
        currencyCode: 'USD',
        entryDate: '2026-10-25',
        description: 'Monthly salary deposit',
        idempotencyKey: 'salary-2026-10-25',
        postings: [
            new PostingInstruction(ledgerAccountId: $checking->ledgerAccount->id, debitAmountMinor: 250000),
            new PostingInstruction(ledgerAccountId: $salaryIncome->id, creditAmountMinor: 250000),
        ],
    ));

    expect($salaryResult->wasRecentlyCreated)->toBeTrue()
        ->and($salaryResult->journalEntry->postings)->toHaveCount(2)
        ->and($salaryResult->journalEntry->request_fingerprint)->toHaveLength(64);

    // 2. Spend $125.50 on Groceries using Credit Card (Liability increases with credit)
    $postingService->post(new PostJournalEntryInput(
        user: $user,
        currencyCode: 'USD',
        entryDate: '2026-10-26',
        description: 'Supermarket purchase',
        idempotencyKey: 'groceries-2026-10-26',
        postings: [
            new PostingInstruction(ledgerAccountId: $groceriesExpense->id, debitAmountMinor: 12550),
            new PostingInstruction(ledgerAccountId: $creditCard->ledgerAccount->id, creditAmountMinor: 12550),
        ],
    ));

    // 3. Pay $50.00 toward Credit Card from Main Checking (Liability decreases with debit, Asset decreases with credit)
    $postingService->post(new PostJournalEntryInput(
        user: $user,
        currencyCode: 'USD',
        entryDate: '2026-10-27',
        description: 'Partial credit card payment',
        idempotencyKey: 'cc-payment-2026-10-27',
        postings: [
            new PostingInstruction(ledgerAccountId: $creditCard->ledgerAccount->id, debitAmountMinor: 5000),
            new PostingInstruction(ledgerAccountId: $checking->ledgerAccount->id, creditAmountMinor: 5000),
        ],
    ));

    $checkingBalance = $balanceService->getBalanceForLedgerAccount($checking->ledgerAccount);
    $cardBalance = $balanceService->getBalanceForLedgerAccount($creditCard->ledgerAccount);

    expect($checkingBalance->amountMinor)->toBe(245000)
        ->and($checkingBalance->format('$'))->toBe('$2,450.00')
        ->and($cardBalance->amountMinor)->toBe(7550)
        ->and($cardBalance->format('$'))->toBe('$75.50');
});

test('unbalanced, single-posting, zero, and negative journal entries are rejected', function (): void {
    $user = User::factory()->create();
    $accountService = app(FinancialAccountService::class);
    $postingService = app(LedgerPostingService::class);

    $checking = $accountService->createAccount($user, [
        'name' => 'Checking',
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);
    $savings = $accountService->createAccount($user, [
        'name' => 'Savings',
        'type' => 'savings',
        'currency_code' => 'USD',
    ]);

    // Unbalanced
    expect(fn () => $postingService->post(new PostJournalEntryInput(
        user: $user,
        currencyCode: 'USD',
        entryDate: '2026-10-27',
        description: 'Unbalanced transfer',
        idempotencyKey: 'unbalanced-1',
        postings: [
            new PostingInstruction(ledgerAccountId: $checking->ledgerAccount->id, debitAmountMinor: 10000),
            new PostingInstruction(ledgerAccountId: $savings->ledgerAccount->id, creditAmountMinor: 9999),
        ],
    )))->toThrow(LedgerIntegrityException::class, 'Unbalanced journal entry');

    // Single posting
    expect(fn () => $postingService->post(new PostJournalEntryInput(
        user: $user,
        currencyCode: 'USD',
        entryDate: '2026-10-27',
        description: 'Single line',
        idempotencyKey: 'single-1',
        postings: [
            new PostingInstruction(ledgerAccountId: $checking->ledgerAccount->id, debitAmountMinor: 10000),
        ],
    )))->toThrow(LedgerIntegrityException::class, 'at least two postings');

    // Zero posting
    expect(fn () => $postingService->post(new PostJournalEntryInput(
        user: $user,
        currencyCode: 'USD',
        entryDate: '2026-10-27',
        description: 'Zero line',
        idempotencyKey: 'zero-1',
        postings: [
            new PostingInstruction(ledgerAccountId: $checking->ledgerAccount->id, debitAmountMinor: 0, creditAmountMinor: 0),
            new PostingInstruction(ledgerAccountId: $savings->ledgerAccount->id, debitAmountMinor: 0, creditAmountMinor: 0),
        ],
    )))->toThrow(LedgerIntegrityException::class, 'strictly positive integer amount');

    // Negative posting
    expect(fn () => $postingService->post(new PostJournalEntryInput(
        user: $user,
        currencyCode: 'USD',
        entryDate: '2026-10-27',
        description: 'Negative line',
        idempotencyKey: 'neg-1',
        postings: [
            new PostingInstruction(ledgerAccountId: $checking->ledgerAccount->id, debitAmountMinor: -500),
            new PostingInstruction(ledgerAccountId: $savings->ledgerAccount->id, creditAmountMinor: -500),
        ],
    )))->toThrow(LedgerIntegrityException::class, 'non-negative integers');

    // Both debit and credit positive on one posting
    expect(fn () => $postingService->post(new PostJournalEntryInput(
        user: $user,
        currencyCode: 'USD',
        entryDate: '2026-10-27',
        description: 'Ambiguous line',
        idempotencyKey: 'ambiguous-1',
        postings: [
            new PostingInstruction(ledgerAccountId: $checking->ledgerAccount->id, debitAmountMinor: 500, creditAmountMinor: 500),
            new PostingInstruction(ledgerAccountId: $savings->ledgerAccount->id, creditAmountMinor: 500),
        ],
    )))->toThrow(LedgerIntegrityException::class, 'strictly positive integer amount on exactly one side');

    expect(JournalEntry::query()->count())->toBe(0)
        ->and(JournalPosting::query()->count())->toBe(0);
});

test('database check constraints on journal_postings reject invalid debit and credit rows directly', function (int $debit, int $credit): void {
    $user = User::factory()->create();
    $accountService = app(FinancialAccountService::class);

    $checking = $accountService->createAccount($user, [
        'name' => 'Checking',
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);

    $entry = JournalEntry::query()->create([
        'user_id' => $user->id,
        'currency_code' => 'USD',
        'idempotency_key' => 'db-check-key',
        'request_fingerprint' => str_repeat('a', 64),
        'entry_date' => '2026-10-27',
        'description' => 'Direct DB constraint check',
        'status' => JournalEntryStatus::Posted,
        'posted_at' => now(),
    ]);

    expect(fn () => DB::table('journal_postings')->insert([
        'journal_entry_id' => $entry->id,
        'ledger_account_id' => $checking->ledgerAccount->id,
        'debit_amount_minor' => $debit,
        'credit_amount_minor' => $credit,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
})->with([
    'zero debit and zero credit' => [0, 0],
    'both debit and credit positive' => [500, 500],
    'negative debit' => [-100, 0],
    'negative credit' => [0, -100],
    'negative debit with positive credit' => [-100, 100],
    'debit exceeding max minor bound' => [Money::MAX_MINOR_AMOUNT + 1, 0],
    'credit exceeding max minor bound' => [0, Money::MAX_MINOR_AMOUNT + 1],
]);

test('cross-user ledger accounts and mixed currencies are rejected', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $accountService = app(FinancialAccountService::class);
    $postingService = app(LedgerPostingService::class);

    $userACheckingUsd = $accountService->createAccount($userA, [
        'name' => 'User A USD',
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);
    $userACheckingPhp = $accountService->createAccount($userA, [
        'name' => 'User A PHP',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);
    $userBCheckingUsd = $accountService->createAccount($userB, [
        'name' => 'User B USD',
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);

    // Cross-user attempt
    expect(fn () => $postingService->post(new PostJournalEntryInput(
        user: $userA,
        currencyCode: 'USD',
        entryDate: '2026-10-27',
        description: 'Cross user attempt',
        idempotencyKey: 'cross-user-1',
        postings: [
            new PostingInstruction(ledgerAccountId: $userACheckingUsd->ledgerAccount->id, debitAmountMinor: 1000),
            new PostingInstruction(ledgerAccountId: $userBCheckingUsd->ledgerAccount->id, creditAmountMinor: 1000),
        ],
    )))->toThrow(LedgerIntegrityException::class, 'same authenticated user');

    // Mixed currency attempt
    expect(fn () => $postingService->post(new PostJournalEntryInput(
        user: $userA,
        currencyCode: 'USD',
        entryDate: '2026-10-27',
        description: 'Mixed currency attempt',
        idempotencyKey: 'mixed-currency-1',
        postings: [
            new PostingInstruction(ledgerAccountId: $userACheckingUsd->ledgerAccount->id, debitAmountMinor: 1000),
            new PostingInstruction(ledgerAccountId: $userACheckingPhp->ledgerAccount->id, creditAmountMinor: 1000),
        ],
    )))->toThrow(LedgerIntegrityException::class, 'does not match journal entry currency');

    // Invalid / non-existent ledger account ID attempt
    expect(fn () => $postingService->post(new PostJournalEntryInput(
        user: $userA,
        currencyCode: 'USD',
        entryDate: '2026-10-27',
        description: 'Non-existent ledger account attempt',
        idempotencyKey: 'non-existent-account-1',
        postings: [
            new PostingInstruction(ledgerAccountId: $userACheckingUsd->ledgerAccount->id, debitAmountMinor: 1000),
            new PostingInstruction(ledgerAccountId: 999999, creditAmountMinor: 1000),
        ],
    )))->toThrow(LedgerIntegrityException::class, 'do not exist');

    // Database-level trigger on journal_postings rejects cross-user or mixed-currency posting rows directly
    $entry = JournalEntry::query()->create([
        'user_id' => $userA->id,
        'currency_code' => 'USD',
        'idempotency_key' => 'raw-posting-guard-entry',
        'request_fingerprint' => str_repeat('b', 64),
        'entry_date' => '2026-10-27',
        'description' => 'Raw posting guard entry',
        'status' => JournalEntryStatus::Posted,
        'posted_at' => now(),
    ]);

    expect(fn () => DB::table('journal_postings')->insert([
        'journal_entry_id' => $entry->id,
        'ledger_account_id' => $userBCheckingUsd->ledgerAccount->id,
        'debit_amount_minor' => 1000,
        'credit_amount_minor' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('journal_postings')->insert([
        'journal_entry_id' => $entry->id,
        'ledger_account_id' => $userACheckingPhp->ledgerAccount->id,
        'debit_amount_minor' => 1000,
        'credit_amount_minor' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('idempotency keys return existing entry for identical payload and throw IdempotencyConflictException for different payloads', function (): void {
    $user = User::factory()->create();
    $accountService = app(FinancialAccountService::class);
    $postingService = app(LedgerPostingService::class);
    $balanceService = app(AccountBalanceService::class);

    $checking = $accountService->createAccount($user, [
        'name' => 'Checking',
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);
    $savings = $accountService->createAccount($user, [
        'name' => 'Savings',
        'type' => 'savings',
        'currency_code' => 'USD',
    ]);

    $input = new PostJournalEntryInput(
        user: $user,
        currencyCode: 'USD',
        entryDate: '2026-10-27',
        description: 'Transfer to savings',
        idempotencyKey: 'transfer-retry-key-001',
        postings: [
            new PostingInstruction(ledgerAccountId: $savings->ledgerAccount->id, debitAmountMinor: 25000),
            new PostingInstruction(ledgerAccountId: $checking->ledgerAccount->id, creditAmountMinor: 25000),
        ],
        externalReference: 'REF-100',
    );

    $firstResult = $postingService->post($input);
    $secondResult = $postingService->post($input);

    // Reordered postings with identical financial meaning also match the canonical fingerprint
    $reorderedInput = new PostJournalEntryInput(
        user: $user,
        currencyCode: 'USD',
        entryDate: '2026-10-27',
        description: 'Transfer to savings',
        idempotencyKey: 'transfer-retry-key-001',
        postings: [
            new PostingInstruction(ledgerAccountId: $checking->ledgerAccount->id, creditAmountMinor: 25000),
            new PostingInstruction(ledgerAccountId: $savings->ledgerAccount->id, debitAmountMinor: 25000),
        ],
        externalReference: 'REF-100',
    );
    $thirdResult = $postingService->post($reorderedInput);

    expect($firstResult->wasRecentlyCreated)->toBeTrue()
        ->and($secondResult->wasRecentlyCreated)->toBeFalse()
        ->and($thirdResult->wasRecentlyCreated)->toBeFalse()
        ->and($secondResult->journalEntry->id)->toBe($firstResult->journalEntry->id)
        ->and($thirdResult->journalEntry->id)->toBe($firstResult->journalEntry->id)
        ->and(JournalEntry::query()->count())->toBe(1)
        ->and(JournalPosting::query()->count())->toBe(2)
        ->and($balanceService->getBalanceForLedgerAccount($savings->ledgerAccount)->amountMinor)->toBe(25000);

    // Reusing the same idempotency key with a different amount must throw IdempotencyConflictException
    $conflictingAmountInput = new PostJournalEntryInput(
        user: $user,
        currencyCode: 'USD',
        entryDate: '2026-10-27',
        description: 'Transfer to savings',
        idempotencyKey: 'transfer-retry-key-001',
        postings: [
            new PostingInstruction(ledgerAccountId: $savings->ledgerAccount->id, debitAmountMinor: 99900),
            new PostingInstruction(ledgerAccountId: $checking->ledgerAccount->id, creditAmountMinor: 99900),
        ],
        externalReference: 'REF-100',
    );

    expect(fn () => $postingService->post($conflictingAmountInput))
        ->toThrow(IdempotencyConflictException::class, 'different journal entry payload');

    // Reusing the same idempotency key with a different description must throw IdempotencyConflictException
    $conflictingDescriptionInput = new PostJournalEntryInput(
        user: $user,
        currencyCode: 'USD',
        entryDate: '2026-10-27',
        description: 'Altered description',
        idempotencyKey: 'transfer-retry-key-001',
        postings: [
            new PostingInstruction(ledgerAccountId: $savings->ledgerAccount->id, debitAmountMinor: 25000),
            new PostingInstruction(ledgerAccountId: $checking->ledgerAccount->id, creditAmountMinor: 25000),
        ],
        externalReference: 'REF-100',
    );

    expect(fn () => $postingService->post($conflictingDescriptionInput))
        ->toThrow(IdempotencyConflictException::class);
});

test('concurrent unique constraint conflicts are resolved safely and unrelated unique constraint failures are rethrown without being treated as idempotency conflicts', function (): void {
    $user = User::factory()->create();
    $accountService = app(FinancialAccountService::class);
    $postingService = app(LedgerPostingService::class);

    $checking = $accountService->createAccount($user, [
        'name' => 'Checking',
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);
    $savings = $accountService->createAccount($user, [
        'name' => 'Savings',
        'type' => 'savings',
        'currency_code' => 'USD',
    ]);

    $input = new PostJournalEntryInput(
        user: $user,
        currencyCode: 'USD',
        entryDate: '2026-10-27',
        description: 'Concurrent transfer',
        idempotencyKey: 'concurrent-key-1',
        postings: [
            new PostingInstruction(ledgerAccountId: $savings->ledgerAccount->id, debitAmountMinor: 15000),
            new PostingInstruction(ledgerAccountId: $checking->ledgerAccount->id, creditAmountMinor: 15000),
        ],
    );

    $expectedFingerprint = $postingService->computeRequestFingerprint(
        userId: $user->id,
        currencyCode: 'USD',
        entryDate: '2026-10-27',
        description: 'Concurrent transfer',
        externalReference: null,
        postings: $input->postings,
    );

    // 1. Insert the winning row outside the nested transaction savepoint, then simulate the raced insert
    // throwing a UniqueConstraintViolationException on journal_entries(user_id, idempotency_key) inside DB::transaction.
    $winningEntry = JournalEntry::query()->create([
        'user_id' => $user->id,
        'currency_code' => 'USD',
        'idempotency_key' => 'concurrent-key-1',
        'request_fingerprint' => $expectedFingerprint,
        'entry_date' => '2026-10-27',
        'description' => 'Concurrent transfer',
        'external_reference' => null,
        'status' => JournalEntryStatus::Posted,
        'posted_at' => now(),
    ]);

    $simulateRaceException = function (string $key) use ($user): void {
        // Trigger a real SQLite unique constraint exception on journal_entries(user_id, idempotency_key)
        DB::table('journal_entries')->insert([
            'user_id' => $user->id,
            'currency_code' => 'USD',
            'idempotency_key' => $key,
            'request_fingerprint' => str_repeat('0', 64),
            'entry_date' => '2026-10-27',
            'description' => 'Duplicate race insert',
            'external_reference' => null,
            'status' => JournalEntryStatus::Posted->value,
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    // Verify that when Currency::retrieved fires inside DB::transaction (simulating a race that bypassed pre-checks)
    // and throws the journal_entries(user_id, idempotency_key) unique violation, post() catches it and resolves $winningEntry.
    $raceInput = new PostJournalEntryInput(
        user: $user,
        currencyCode: 'USD',
        entryDate: '2026-10-27',
        description: 'Concurrent transfer',
        idempotencyKey: 'concurrent-key-2',
        postings: $input->postings,
    );

    $raceFingerprint = $postingService->computeRequestFingerprint(
        userId: $user->id,
        currencyCode: 'USD',
        entryDate: '2026-10-27',
        description: 'Concurrent transfer',
        externalReference: null,
        postings: $raceInput->postings,
    );

    // Pre-commit the winning entry for concurrent-key-2, then test both the in-transaction lock check and exception handler
    JournalEntry::query()->create([
        'user_id' => $user->id,
        'currency_code' => 'USD',
        'idempotency_key' => 'concurrent-key-2',
        'request_fingerprint' => $raceFingerprint,
        'entry_date' => '2026-10-27',
        'description' => 'Concurrent transfer',
        'external_reference' => null,
        'status' => JournalEntryStatus::Posted,
        'posted_at' => now(),
    ]);

    $result = $postingService->post($raceInput);
    expect($result->wasRecentlyCreated)->toBeFalse()
        ->and($result->journalEntry->idempotency_key)->toBe('concurrent-key-2')
        ->and($winningEntry->idempotency_key)->toBe('concurrent-key-1');

    // Also test the catch block directly when JournalEntry::creating throws the unique violation for an existing entry
    $catchKey = 'concurrent-catch-key';
    $catchInput = new PostJournalEntryInput(
        user: $user,
        currencyCode: 'USD',
        entryDate: '2026-10-27',
        description: 'Catch block transfer',
        idempotencyKey: $catchKey,
        postings: $input->postings,
    );

    // If a unique violation on journal_entries(user_id, idempotency_key) occurs during JournalEntry::creating
    // and the winning entry has a conflicting fingerprint, resolveConcurrentEntry throws IdempotencyConflictException.
    JournalEntry::query()->create([
        'user_id' => $user->id,
        'currency_code' => 'USD',
        'idempotency_key' => 'existing-conflict-key',
        'request_fingerprint' => str_repeat('f', 64),
        'entry_date' => '2026-10-27',
        'description' => 'Existing conflicting entry',
        'external_reference' => null,
        'status' => JournalEntryStatus::Posted,
        'posted_at' => now(),
    ]);

    expect(fn () => $simulateRaceException('existing-conflict-key'))
        ->toThrow(QueryException::class);

    expect(fn () => $postingService->post(new PostJournalEntryInput(
        user: $user,
        currencyCode: 'USD',
        entryDate: '2026-10-27',
        description: 'Conflicting retry',
        idempotencyKey: 'existing-conflict-key',
        postings: $input->postings,
    )))->toThrow(IdempotencyConflictException::class);

    // 3. Unrelated unique constraint violation (for example, on ledger_accounts(user_id, code) or another table)
    // must NOT be swallowed or misclassified as an idempotency conflict, even if an entry exists.
    $unrelatedUniqueFailureOnce = true;
    JournalPosting::creating(function () use ($user, $checking, &$unrelatedUniqueFailureOnce): void {
        if (! $unrelatedUniqueFailureOnce) {
            return;
        }
        $unrelatedUniqueFailureOnce = false;

        // Trigger a unique constraint violation on ledger_accounts(user_id, code)
        DB::table('ledger_accounts')->insert([
            'user_id' => $user->id,
            'financial_account_id' => null,
            'code' => $checking->ledgerAccount->code,
            'name' => 'Duplicate Code Collision',
            'classification' => LedgerAccountClassification::Asset->value,
            'currency_code' => 'USD',
            'is_system' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    expect(fn () => $postingService->post($catchInput))
        ->toThrow(QueryException::class);

    expect(JournalEntry::query()->where('idempotency_key', $catchKey)->exists())->toBeFalse();
});

test('in-transaction validation prevents posting if currency or ledger account state is invalidated before transaction lock', function (): void {
    $user = User::factory()->create();
    $accountService = app(FinancialAccountService::class);
    $postingService = app(LedgerPostingService::class);

    $checking = $accountService->createAccount($user, [
        'name' => 'Checking',
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);
    $savings = $accountService->createAccount($user, [
        'name' => 'Savings',
        'type' => 'savings',
        'currency_code' => 'USD',
    ]);

    // Deactivate USD currency right before posting; in-transaction lockForUpdate check must reject it
    Currency::query()->where('code', 'USD')->update(['is_active' => false]);

    expect(fn () => $postingService->post(new PostJournalEntryInput(
        user: $user,
        currencyCode: 'USD',
        entryDate: '2026-10-27',
        description: 'Inactive currency attempt',
        idempotencyKey: 'inactive-currency-key-1',
        postings: [
            new PostingInstruction(ledgerAccountId: $savings->ledgerAccount->id, debitAmountMinor: 5000),
            new PostingInstruction(ledgerAccountId: $checking->ledgerAccount->id, creditAmountMinor: 5000),
        ],
    )))->toThrow(LedgerIntegrityException::class, 'not active or does not exist');
});

test('journal posting rolls back atomically if an error occurs mid-transaction and enforces immutability at both model and database levels', function (): void {
    $user = User::factory()->create();
    $accountService = app(FinancialAccountService::class);
    $postingService = app(LedgerPostingService::class);

    $checking = $accountService->createAccount($user, [
        'name' => 'Checking',
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);
    $savings = $accountService->createAccount($user, [
        'name' => 'Savings',
        'type' => 'savings',
        'currency_code' => 'USD',
    ]);

    $auditCountBefore = AuditEvent::query()->count();

    // Simulate failure after JournalEntry creation during JournalPosting creation
    $shouldFailOnce = true;
    JournalPosting::creating(function () use (&$shouldFailOnce): void {
        if ($shouldFailOnce) {
            $shouldFailOnce = false;

            throw new RuntimeException('Simulated posting failure mid-transaction');
        }
    });

    expect(fn () => $postingService->post(new PostJournalEntryInput(
        user: $user,
        currencyCode: 'USD',
        entryDate: '2026-10-27',
        description: 'Rollback entry',
        idempotencyKey: 'rollback-entry-1',
        postings: [
            new PostingInstruction(ledgerAccountId: $checking->ledgerAccount->id, debitAmountMinor: 5000),
            new PostingInstruction(ledgerAccountId: $savings->ledgerAccount->id, creditAmountMinor: 5000),
        ],
    )))->toThrow(RuntimeException::class, 'Simulated posting failure mid-transaction');

    expect(JournalEntry::query()->count())->toBe(0)
        ->and(JournalPosting::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe($auditCountBefore);

    // Overflow rejection
    expect(fn () => $postingService->post(new PostJournalEntryInput(
        user: $user,
        currencyCode: 'USD',
        entryDate: '2026-10-27',
        description: 'Overflow entry',
        idempotencyKey: 'overflow-1',
        postings: [
            new PostingInstruction(ledgerAccountId: $checking->ledgerAccount->id, debitAmountMinor: Money::MAX_MINOR_AMOUNT + 10),
            new PostingInstruction(ledgerAccountId: $savings->ledgerAccount->id, creditAmountMinor: Money::MAX_MINOR_AMOUNT + 10),
        ],
    )))->toThrow(LedgerIntegrityException::class);

    $result = $postingService->post(new PostJournalEntryInput(
        user: $user,
        currencyCode: 'USD',
        entryDate: '2026-10-27',
        description: 'Immutable transfer',
        idempotencyKey: 'immutable-transfer-1',
        postings: [
            new PostingInstruction(ledgerAccountId: $savings->ledgerAccount->id, debitAmountMinor: 10000),
            new PostingInstruction(ledgerAccountId: $checking->ledgerAccount->id, creditAmountMinor: 10000),
        ],
    ));

    $entry = $result->journalEntry;
    $posting = $entry->postings->firstOrFail();

    // Eloquent model hooks reject mutation and deletion
    expect(fn () => $entry->update(['description' => 'Tampered']))
        ->toThrow(ImmutableRecordException::class);

    expect(fn () => $entry->delete())
        ->toThrow(ImmutableRecordException::class);

    expect(fn () => $posting->update(['debit_amount_minor' => 99999]))
        ->toThrow(ImmutableRecordException::class);

    expect(fn () => $posting->delete())
        ->toThrow(ImmutableRecordException::class);

    // Database triggers also reject direct query-builder updates and deletes
    expect(fn () => DB::table('journal_entries')->where('id', $entry->id)->update(['description' => 'Raw SQL tamper']))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('journal_entries')->where('id', $entry->id)->delete())
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('journal_postings')->where('id', $posting->id)->update(['debit_amount_minor' => 99999]))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('journal_postings')->where('id', $posting->id)->delete())
        ->toThrow(QueryException::class);
});

test('financial migrations roll back cleanly in reverse dependency order and re-migrate without orphaned triggers or tables', function (): void {
    $migrationFiles = [
        base_path('database/migrations/2026_10_27_000006_create_audit_events_table.php'),
        base_path('database/migrations/2026_10_27_000005_create_journal_postings_table.php'),
        base_path('database/migrations/2026_10_27_000004_create_journal_entries_table.php'),
        base_path('database/migrations/2026_10_27_000003_create_ledger_accounts_table.php'),
        base_path('database/migrations/2026_10_27_000002_create_financial_accounts_table.php'),
        base_path('database/migrations/2026_10_27_000001_create_currencies_table.php'),
    ];

    foreach ($migrationFiles as $file) {
        $migration = require $file;
        $migration->down();
    }

    expect(Schema::hasTable('audit_events'))->toBeFalse()
        ->and(Schema::hasTable('journal_postings'))->toBeFalse()
        ->and(Schema::hasTable('journal_entries'))->toBeFalse()
        ->and(Schema::hasTable('ledger_accounts'))->toBeFalse()
        ->and(Schema::hasTable('financial_accounts'))->toBeFalse()
        ->and(Schema::hasTable('currencies'))->toBeFalse();

    foreach (array_reverse($migrationFiles) as $file) {
        $migration = require $file;
        $migration->up();
    }

    expect(Schema::hasTable('currencies'))->toBeTrue()
        ->and(Schema::hasTable('financial_accounts'))->toBeTrue()
        ->and(Schema::hasTable('ledger_accounts'))->toBeTrue()
        ->and(Schema::hasTable('journal_entries'))->toBeTrue()
        ->and(Schema::hasTable('journal_postings'))->toBeTrue()
        ->and(Schema::hasTable('audit_events'))->toBeTrue();
});
