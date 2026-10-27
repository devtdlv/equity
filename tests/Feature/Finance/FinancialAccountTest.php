<?php

use App\DataTransferObjects\CreateFinancialTransactionInput;
use App\DataTransferObjects\PostingInstruction;
use App\DataTransferObjects\PostJournalEntryInput;
use App\Enums\CategoryType;
use App\Enums\FinancialAccountType;
use App\Enums\FinancialTransactionType;
use App\Enums\LedgerAccountClassification;
use App\Exceptions\ImmutableRecordException;
use App\Exceptions\LedgerIntegrityException;
use App\Models\AuditEvent;
use App\Models\FinancialAccount;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\AuditService;
use App\Services\CategoryService;
use App\Services\FinancialAccountService;
use App\Services\FinancialTransactionService;
use App\Services\LedgerPostingService;
use Database\Seeders\CurrencySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CurrencySeeder::class);
});

test('guests are redirected to login when accessing account routes', function (): void {
    $this->get(route('accounts.index'))->assertRedirect(route('login'));
    $this->post(route('accounts.store'), [])->assertRedirect(route('login'));
});

test('authenticated users can create an account with a corresponding ledger account and zero balance', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('accounts.store'), [
        'name' => 'Primary Checking',
        'type' => 'checking',
        'currency_code' => 'PHP',
        'institution' => 'BPI',
        'description' => 'Household operating checking account',
    ]);

    $response->assertRedirect(route('accounts.index'));

    $account = FinancialAccount::query()->where('user_id', $user->id)->first();
    expect($account)->not->toBeNull()
        ->and($account?->name)->toBe('Primary Checking')
        ->and($account?->type)->toBe(FinancialAccountType::Checking)
        ->and($account?->currency_code)->toBe('PHP')
        ->and($account?->institution)->toBe('BPI')
        ->and($account?->is_archived)->toBeFalse();

    $ledgerAccount = $account?->ledgerAccount;
    expect($ledgerAccount)->not->toBeNull()
        ->and($ledgerAccount?->user_id)->toBe($user->id)
        ->and($ledgerAccount?->classification)->toBe(LedgerAccountClassification::Asset)
        ->and($ledgerAccount?->currency_code)->toBe('PHP')
        ->and($ledgerAccount?->code)->toBe("ACCT-{$account?->id}");

    $this->actingAs($user)
        ->get(route('accounts.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Accounts/Index')
            ->has('accounts', 1)
            ->where('accounts.0.name', 'Primary Checking')
            ->where('accounts.0.balance.amount_minor', 0)
            ->where('accounts.0.balance.formatted', '₱0.00')
            ->where('accounts.0.balance.is_liability', false)
        );
});

test('credit card accounts create liability ledger accounts', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('accounts.store'), [
        'name' => 'Travel Rewards Visa',
        'type' => 'credit_card',
        'currency_code' => 'USD',
    ])->assertRedirect(route('accounts.index'));

    $account = FinancialAccount::query()->where('user_id', $user->id)->firstOrFail();

    expect($account->type)->toBe(FinancialAccountType::CreditCard)
        ->and($account->ledgerAccount->classification)->toBe(LedgerAccountClassification::Liability);
});

test('cross-user financial-account and ledger-account mappings are rejected at both model and database levels', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $financialAccount = FinancialAccount::query()->create([
        'user_id' => $owner->id,
        'name' => 'Owner Checking',
        'type' => FinancialAccountType::Checking,
        'currency_code' => 'USD',
        'is_archived' => false,
    ]);

    // Model-level guard rejects cross-user mapping
    expect(fn () => LedgerAccount::query()->create([
        'user_id' => $otherUser->id,
        'financial_account_id' => $financialAccount->id,
        'code' => 'CROSS-USER-1',
        'name' => 'Cross User Ledger',
        'classification' => LedgerAccountClassification::Asset,
        'currency_code' => 'USD',
        'is_system' => false,
    ]))->toThrow(LedgerIntegrityException::class, 'Ledger account owner must match its linked financial account owner.');

    // Model-level guard rejects currency mismatch with linked financial account
    expect(fn () => LedgerAccount::query()->create([
        'user_id' => $owner->id,
        'financial_account_id' => $financialAccount->id,
        'code' => 'CURRENCY-MISMATCH-1',
        'name' => 'Mismatched Currency Ledger',
        'classification' => LedgerAccountClassification::Asset,
        'currency_code' => 'PHP',
        'is_system' => false,
    ]))->toThrow(LedgerIntegrityException::class, 'Ledger account currency must match its linked financial account currency.');

    // Model-level guard rejects classification mismatch with linked financial account
    expect(fn () => LedgerAccount::query()->create([
        'user_id' => $owner->id,
        'financial_account_id' => $financialAccount->id,
        'code' => 'CLASS-MISMATCH-1',
        'name' => 'Mismatched Classification Ledger',
        'classification' => LedgerAccountClassification::Liability,
        'currency_code' => 'USD',
        'is_system' => false,
    ]))->toThrow(LedgerIntegrityException::class, 'Ledger account classification must match its linked financial account type.');

    // Database-level composite foreign key (financial_account_id, user_id) rejects raw SQL cross-user insert
    expect(fn () => DB::table('ledger_accounts')->insert([
        'user_id' => $otherUser->id,
        'financial_account_id' => $financialAccount->id,
        'code' => 'RAW-CROSS-USER',
        'name' => 'Raw Cross User',
        'classification' => LedgerAccountClassification::Asset->value,
        'currency_code' => 'USD',
        'is_system' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('account creation rolls back atomically if ledger account creation fails', function (): void {
    $user = User::factory()->create();

    $shouldFailOnce = true;
    LedgerAccount::creating(function () use (&$shouldFailOnce): void {
        if ($shouldFailOnce) {
            $shouldFailOnce = false;

            throw new RuntimeException('Simulated ledger failure');
        }
    });

    expect(fn () => app(FinancialAccountService::class)->createAccount($user, [
        'name' => 'Rollback Test Account',
        'type' => 'checking',
        'currency_code' => 'USD',
    ]))->toThrow(RuntimeException::class, 'Simulated ledger failure');

    expect(FinancialAccount::query()->count())->toBe(0)
        ->and(LedgerAccount::query()->count())->toBe(0);
});

test('authenticated users can edit, archive, and restore their own accounts', function (): void {
    $user = User::factory()->create();
    $service = app(FinancialAccountService::class);

    $account = $service->createAccount($user, [
        'name' => 'Emergency Reserve',
        'type' => 'savings',
        'currency_code' => 'USD',
        'institution' => 'Ally',
    ]);

    $this->actingAs($user)
        ->patch(route('accounts.update', $account), [
            'name' => 'High-Yield Reserve',
            'type' => 'savings',
            'currency_code' => 'AUD',
            'institution' => 'Macquarie',
            'description' => 'Updated reserve account',
        ])
        ->assertRedirect(route('accounts.index'));

    $account->refresh();
    expect($account->name)->toBe('High-Yield Reserve')
        ->and($account->currency_code)->toBe('AUD')
        ->and($account->institution)->toBe('Macquarie')
        ->and($account->ledgerAccount->name)->toBe('High-Yield Reserve')
        ->and($account->ledgerAccount->currency_code)->toBe('AUD');

    $this->actingAs($user)
        ->post(route('accounts.archive', $account))
        ->assertRedirect(route('accounts.index'));

    $account->refresh();
    expect($account->is_archived)->toBeTrue()
        ->and($account->archived_at)->not->toBeNull();

    $this->actingAs($user)
        ->post(route('accounts.restore', $account))
        ->assertRedirect(route('accounts.index'));

    $account->refresh();
    expect($account->is_archived)->toBeFalse()
        ->and($account->archived_at)->toBeNull();
});

test('users cannot view, update, archive, or restore another users account', function (): void {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();

    $ownerAccount = app(FinancialAccountService::class)->createAccount($owner, [
        'name' => 'Owner Private Vault',
        'type' => 'savings',
        'currency_code' => 'USD',
    ]);

    $this->actingAs($intruder)
        ->get(route('accounts.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('accounts', 0)
        );

    $this->actingAs($intruder)
        ->patch(route('accounts.update', $ownerAccount), [
            'name' => 'Hijacked Name',
            'type' => 'savings',
            'currency_code' => 'USD',
        ])
        ->assertForbidden();

    $this->actingAs($intruder)
        ->post(route('accounts.archive', $ownerAccount))
        ->assertForbidden();

    $this->actingAs($intruder)
        ->post(route('accounts.restore', $ownerAccount))
        ->assertForbidden();

    $ownerAccount->refresh();
    expect($ownerAccount->name)->toBe('Owner Private Vault');
});

test('account currency becomes immutable once ledger activity exists', function (): void {
    $user = User::factory()->create();
    $accountService = app(FinancialAccountService::class);
    $postingService = app(LedgerPostingService::class);

    $account = $accountService->createAccount($user, [
        'name' => 'Operating USD',
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);

    $openingEquity = LedgerAccount::query()->create([
        'user_id' => $user->id,
        'code' => 'EQUITY-OPENING-USD',
        'name' => 'Opening Balances Equity',
        'classification' => LedgerAccountClassification::Equity,
        'currency_code' => 'USD',
        'is_system' => true,
    ]);

    $postingService->post(new PostJournalEntryInput(
        user: $user,
        currencyCode: 'USD',
        entryDate: '2026-10-27',
        description: 'Initial deposit',
        idempotencyKey: 'init-deposit-1',
        postings: [
            new PostingInstruction(ledgerAccountId: $account->ledgerAccount->id, debitAmountMinor: 50000),
            new PostingInstruction(ledgerAccountId: $openingEquity->id, creditAmountMinor: 50000),
        ],
    ));

    $response = $this->actingAs($user)
        ->from(route('accounts.index'))
        ->patch(route('accounts.update', $account), [
            'name' => 'Operating USD',
            'type' => 'checking',
            'currency_code' => 'PHP',
        ]);

    $response->assertRedirect(route('accounts.index'));
    $response->assertSessionHasErrors('currency_code');

    expect(fn () => $account->update(['currency_code' => 'PHP']))
        ->toThrow(ImmutableRecordException::class);

    // Switching between Asset (Checking) and Liability (CreditCard) after posted activity is also rejected
    expect(fn () => $accountService->updateAccount($user, $account, [
        'name' => 'Operating USD',
        'type' => FinancialAccountType::CreditCard,
        'currency_code' => 'USD',
    ]))->toThrow(ImmutableRecordException::class, 'cannot switch between asset and liability classifications');

    expect(fn () => $account->delete())
        ->toThrow(LedgerIntegrityException::class);
});

test('account archive and restore reload and lock current state, preserve idempotency with stale model instances, and do not overwrite newer edits or emit duplicate audit events', function (): void {
    $user = User::factory()->create();
    $accountService = app(FinancialAccountService::class);

    $account = $accountService->createAccount($user, [
        'name' => 'Original Name',
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);

    // Hold a stale in-memory copy of the model before concurrent updates happen in the database
    $staleActiveInstance = FinancialAccount::query()->findOrFail($account->id);

    // Update the account name and archive it via a fresh operation
    $accountService->updateAccount($user, $account, [
        'name' => 'Updated Name Before Archive',
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);
    $archivedOnce = $accountService->archiveAccount($user, $account);

    $archiveAuditCount = AuditEvent::query()
        ->where('auditable_type', FinancialAccount::class)
        ->where('auditable_id', $account->id)
        ->where('action', AuditService::ACTION_ACCOUNT_ARCHIVED)
        ->count();

    expect($archivedOnce->is_archived)->toBeTrue()
        ->and($archivedOnce->name)->toBe('Updated Name Before Archive')
        ->and($archiveAuditCount)->toBe(1);

    // Calling archiveAccount again using the stale (is_archived = false, name = 'Original Name') instance
    // must reload the locked row, detect it is already archived, preserve the newer name, and emit NO duplicate audit event.
    $archivedAgainWithStale = $accountService->archiveAccount($user, $staleActiveInstance);

    $archiveAuditCountAfterRetry = AuditEvent::query()
        ->where('auditable_type', FinancialAccount::class)
        ->where('auditable_id', $account->id)
        ->where('action', AuditService::ACTION_ACCOUNT_ARCHIVED)
        ->count();

    expect($archivedAgainWithStale->is_archived)->toBeTrue()
        ->and($archivedAgainWithStale->name)->toBe('Updated Name Before Archive')
        ->and($archiveAuditCountAfterRetry)->toBe(1);

    // Hold a stale archived copy, then restore and rename in the database
    $staleArchivedInstance = FinancialAccount::query()->findOrFail($account->id);
    $restoredOnce = $accountService->restoreAccount($user, $account);
    $accountService->updateAccount($user, $restoredOnce, [
        'name' => 'Updated Name After Restore',
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);

    // Calling restoreAccount again with the stale archived instance must reload the locked row,
    // preserve the newer name, and emit NO duplicate restore audit event.
    $restoredAgainWithStale = $accountService->restoreAccount($user, $staleArchivedInstance);

    $restoreAuditCount = AuditEvent::query()
        ->where('auditable_type', FinancialAccount::class)
        ->where('auditable_id', $account->id)
        ->where('action', AuditService::ACTION_ACCOUNT_RESTORED)
        ->count();

    expect($restoredAgainWithStale->is_archived)->toBeFalse()
        ->and($restoredAgainWithStale->name)->toBe('Updated Name After Restore')
        ->and($restoreAuditCount)->toBe(1);
});

test('account update and transaction creation acquire table locks in a consistent order without lock-order inversion', function (): void {
    $user = User::factory()->create();
    $accountService = app(FinancialAccountService::class);
    $categoryService = app(CategoryService::class);
    $transactionService = app(FinancialTransactionService::class);

    $account = $accountService->createAccount($user, [
        'name' => 'Lock Order Checking',
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);

    $category = $categoryService->createCategory($user, [
        'name' => 'Consulting',
        'type' => CategoryType::Income,
    ]);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $sql = strtolower($query->sql);
        if (str_starts_with($sql, 'select')) {
            $queries[] = $sql;
        }
    });

    // 1. Account update WITH currency change: locks financial_accounts -> currencies -> ledger_accounts
    $queries = [];
    $accountService->updateAccount($user, $account, [
        'name' => 'Lock Order Checking PHP',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);

    $updateCurrencyTables = [];
    foreach ($queries as $sql) {
        if (str_contains($sql, '"financial_accounts"') && ! in_array('financial_accounts', $updateCurrencyTables, true)) {
            $updateCurrencyTables[] = 'financial_accounts';
        } elseif (str_contains($sql, '"currencies"') && ! in_array('currencies', $updateCurrencyTables, true)) {
            $updateCurrencyTables[] = 'currencies';
        } elseif (str_contains($sql, '"ledger_accounts"') && ! in_array('ledger_accounts', $updateCurrencyTables, true)) {
            $updateCurrencyTables[] = 'ledger_accounts';
        }
    }

    expect($updateCurrencyTables)->toBe([
        'financial_accounts',
        'currencies',
        'ledger_accounts',
    ]);

    // 2. Account update WITHOUT currency change: locks financial_accounts -> ledger_accounts (skips unnecessary currency lock)
    $queries = [];
    $accountService->updateAccount($user, $account, [
        'name' => 'Lock Order Checking Renamed',
        'type' => 'checking',
    ]);

    $updateNameQueriesOnCurrencies = array_filter(
        $queries,
        static fn (string $sql): bool => str_contains($sql, 'from "currencies" where "code" = ?'),
    );
    expect($updateNameQueriesOnCurrencies)->toBeEmpty();

    // 3. Transaction creation: locks financial_accounts -> categories -> currencies -> ledger_accounts
    $queries = [];
    $transactionService->createTransaction(new CreateFinancialTransactionInput(
        user: $user,
        type: FinancialTransactionType::Income,
        financialAccountId: $account->id,
        categoryId: $category->id,
        amount: '500.00',
        entryDate: '2026-10-27',
        description: 'Lock order verification income',
        idempotencyKey: 'aa0e8400-e29b-41d4-a716-446655440001',
    ));

    $txLockedTables = [];
    foreach ($queries as $sql) {
        if (str_contains($sql, 'from "financial_accounts"') && ! in_array('financial_accounts', $txLockedTables, true)) {
            $txLockedTables[] = 'financial_accounts';
        } elseif (str_contains($sql, 'from "categories"') && ! in_array('categories', $txLockedTables, true)) {
            $txLockedTables[] = 'categories';
        } elseif (str_contains($sql, 'from "currencies"') && ! in_array('currencies', $txLockedTables, true)) {
            $txLockedTables[] = 'currencies';
        } elseif (str_contains($sql, 'from "ledger_accounts" where "category_id"') && ! in_array('ledger_accounts', $txLockedTables, true)) {
            $txLockedTables[] = 'ledger_accounts';
        }
    }

    expect($txLockedTables)->toBe([
        'financial_accounts',
        'categories',
        'currencies',
        'ledger_accounts',
    ]);
});
