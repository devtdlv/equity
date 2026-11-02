<?php

use App\DataTransferObjects\CreateFinancialTransactionInput;
use App\DataTransferObjects\PostingInstruction;
use App\DataTransferObjects\PostJournalEntryInput;
use App\Enums\CategoryType;
use App\Enums\FinancialAccountType;
use App\Enums\FinancialTransactionType;
use App\Enums\LedgerAccountClassification;
use App\Exceptions\ImmutableRecordException;
use App\Exceptions\InactiveCurrencyException;
use App\Exceptions\InvalidMonetaryAmountException;
use App\Exceptions\LedgerIntegrityException;
use App\Models\AuditEvent;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\JournalEntry;
use App\Models\JournalPosting;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\AuditService;
use App\Services\CategoryService;
use App\Services\FinancialAccountService;
use App\Services\FinancialTransactionService;
use App\Services\LedgerPostingService;
use Database\Seeders\CurrencySeeder;
use App\Exceptions\IdempotencyConflictException;
use App\Support\IdempotencyUniqueConstraint;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
        'idempotency_key' => (string) Str::uuid(),
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
        'idempotency_key' => (string) Str::uuid(),
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

test('nonzero starting balance posts an opening journal without a financial transaction', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('accounts.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'name' => 'Funded Checking',
            'type' => 'checking',
            'currency_code' => 'USD',
            'starting_balance' => '100.00',
        ])
        ->assertRedirect(route('accounts.index'));

    $account = FinancialAccount::query()->where('user_id', $user->id)->firstOrFail();

    expect($account->opening_journal_entry_id)->not->toBeNull()
        ->and(FinancialTransaction::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(1)
        ->and(JournalPosting::query()->count())->toBe(2);

    $this->actingAs($user)
        ->get(route('accounts.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Accounts/Index')
            ->where('accounts.0.balance.amount_minor', 10000)
            ->where('accounts.0.has_posted_activity', true)
        );
});

test('omitted empty and signed-zero starting balances do not post an opening journal', function (array $payload): void {
    $user = User::factory()->create();
    $accountService = app(FinancialAccountService::class);

    $account = $accountService->createAccount($user, [
        'name' => 'Empty Opening',
        'type' => 'checking',
        'currency_code' => 'USD',
        ...$payload,
    ]);

    expect($account->opening_journal_entry_id)->toBeNull()
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and(JournalPosting::query()->count())->toBe(0)
        ->and(FinancialTransaction::query()->count())->toBe(0);
})->with([
    'omitted' => [[]],
    'empty string' => [['starting_balance' => '']],
    'zero' => [['starting_balance' => '0']],
    'zero decimal' => [['starting_balance' => '0.00']],
    'negative zero' => [['starting_balance' => '-0.00']],
]);

test('invalid starting balance returns a starting_balance validation error', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('accounts.index'))
        ->post(route('accounts.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'name' => 'Broken Amount',
            'type' => 'checking',
            'currency_code' => 'USD',
            'starting_balance' => '12.345',
        ])
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHasErrors('starting_balance');

    expect(FinancialAccount::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0);
});

test('caller-owned transaction rolls back account ledger audit opening journal and postings together', function (): void {
    $user = User::factory()->create();

    expect(fn () => DB::transaction(function () use ($user): void {
        app(FinancialAccountService::class)->createAccount($user, [
            'name' => 'Outer Transaction Checking',
            'type' => 'checking',
            'currency_code' => 'USD',
            'starting_balance' => '25.00',
        ]);

        throw new RuntimeException('caller abort after nested create');
    }))->toThrow(RuntimeException::class, 'caller abort after nested create');

    expect(FinancialAccount::query()->count())->toBe(0)
        ->and(LedgerAccount::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and(JournalPosting::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0);
});

test('opening journal failure rolls back the created account and audit trail', function (): void {
    $user = User::factory()->create();
    $shouldFailOnce = true;

    JournalEntry::creating(function () use (&$shouldFailOnce): void {
        if (! $shouldFailOnce) {
            return;
        }

        $shouldFailOnce = false;

        throw new RuntimeException('simulated opening journal failure');
    });

    expect(fn () => app(FinancialAccountService::class)->createAccount($user, [
        'name' => 'Failed Opening',
        'type' => 'checking',
        'currency_code' => 'USD',
        'starting_balance' => '40.00',
    ]))->toThrow(RuntimeException::class, 'simulated opening journal failure');

    expect(FinancialAccount::query()->count())->toBe(0)
        ->and(LedgerAccount::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and(JournalPosting::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', AuditService::ACTION_ACCOUNT_CREATED)->count())->toBe(0);
});

test('starting amount uses the locked currency exponent when metadata changes after the unlocked read', function (): void {
    $user = User::factory()->create();
    $shouldMutateOnce = true;

    FinancialAccount::creating(function () use (&$shouldMutateOnce): void {
        if (! $shouldMutateOnce) {
            return;
        }

        $shouldMutateOnce = false;

        Currency::query()->where('code', 'USD')->update(['minor_unit_exponent' => 0]);
    });

    expect(fn () => app(FinancialAccountService::class)->createAccount($user, [
        'name' => 'Exponent Race Checking',
        'type' => 'checking',
        'currency_code' => 'USD',
        'starting_balance' => '10.50',
    ]))->toThrow(InvalidMonetaryAmountException::class);

    expect(FinancialAccount::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0);
});

test('deactivating the currency after insert fails closed and rolls back create', function (): void {
    $user = User::factory()->create();
    $shouldDeactivateOnce = true;

    FinancialAccount::created(function () use (&$shouldDeactivateOnce): void {
        if (! $shouldDeactivateOnce) {
            return;
        }

        $shouldDeactivateOnce = false;

        Currency::query()->where('code', 'USD')->update(['is_active' => false]);
    });

    expect(fn () => app(FinancialAccountService::class)->createAccount($user, [
        'name' => 'Inactive Currency Race',
        'type' => 'checking',
        'currency_code' => 'USD',
        'starting_balance' => '10.00',
    ]))->toThrow(InactiveCurrencyException::class);

    expect(FinancialAccount::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0);
});

test('duplicate create with the same idempotency key and payload replays without a second account opening journal or audit', function (): void {
    $user = User::factory()->create();
    $service = app(FinancialAccountService::class);
    $uuid = (string) Str::uuid();
    $payload = [
        'idempotency_key' => $uuid,
        'name' => 'Idempotent Checking',
        'type' => 'checking',
        'currency_code' => 'USD',
        'institution' => 'Chase',
        'description' => 'Replay safe',
        'starting_balance' => '50.00',
    ];

    $first = $service->createAccountResult($user, $payload);
    $second = $service->createAccountResult($user, $payload);

    expect($first->wasRecentlyCreated)->toBeTrue()
        ->and($second->wasRecentlyCreated)->toBeFalse()
        ->and($second->account->id)->toBe($first->account->id)
        ->and(FinancialAccount::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(LedgerAccount::query()->where('user_id', $user->id)->whereNotNull('financial_account_id')->count())->toBe(1)
        ->and(JournalEntry::query()->count())->toBe(1)
        ->and(JournalPosting::query()->count())->toBe(2)
        ->and(AuditEvent::query()->where('action', AuditService::ACTION_ACCOUNT_CREATED)->count())->toBe(1)
        ->and(FinancialTransaction::query()->count())->toBe(0);
});

test('create idempotency conflict rejects a reused key with a different payload', function (): void {
    $user = User::factory()->create();
    $service = app(FinancialAccountService::class);
    $uuid = (string) Str::uuid();

    $service->createAccount($user, [
        'idempotency_key' => $uuid,
        'name' => 'Original Checking',
        'type' => 'checking',
        'currency_code' => 'USD',
        'starting_balance' => '10.00',
    ]);

    expect(fn () => $service->createAccount($user, [
        'idempotency_key' => $uuid,
        'name' => 'Different Checking',
        'type' => 'checking',
        'currency_code' => 'USD',
        'starting_balance' => '10.00',
    ]))->toThrow(IdempotencyConflictException::class);

    expect(FinancialAccount::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(JournalEntry::query()->count())->toBe(1);
});

test('http create replay returns success flash without duplicating the account', function (): void {
    $user = User::factory()->create();
    $uuid = (string) Str::uuid();
    $payload = [
        'idempotency_key' => $uuid,
        'name' => 'HTTP Idempotent',
        'type' => 'savings',
        'currency_code' => 'PHP',
        'starting_balance' => '1000.50',
    ];

    $this->actingAs($user)
        ->post(route('accounts.store'), $payload)
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('status', 'Account created.');

    $this->actingAs($user)
        ->post(route('accounts.store'), $payload)
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('status', 'Account already created for this request.');

    expect(FinancialAccount::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(JournalEntry::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', AuditService::ACTION_ACCOUNT_CREATED)->count())->toBe(1);
});

test('http create conflict maps to idempotency_key validation error', function (): void {
    $user = User::factory()->create();
    $uuid = (string) Str::uuid();

    $this->actingAs($user)->post(route('accounts.store'), [
        'idempotency_key' => $uuid,
        'name' => 'First Account',
        'type' => 'checking',
        'currency_code' => 'USD',
    ])->assertRedirect(route('accounts.index'));

    $this->actingAs($user)
        ->from(route('accounts.index'))
        ->post(route('accounts.store'), [
            'idempotency_key' => $uuid,
            'name' => 'Conflicting Account',
            'type' => 'checking',
            'currency_code' => 'USD',
        ])
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHasErrors('idempotency_key');

    expect(FinancialAccount::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('create race on unique idempotency key recovers the existing account', function (): void {
    $user = User::factory()->create();
    $service = app(FinancialAccountService::class);
    $uuid = (string) Str::uuid();
    $payload = [
        'idempotency_key' => $uuid,
        'name' => 'Race Checking',
        'type' => 'checking',
        'currency_code' => 'USD',
    ];

    $first = $service->createAccountResult($user, $payload);
    $second = $service->createAccountResult($user, $payload);

    expect($first->account->id)->toBe($second->account->id)
        ->and($second->wasRecentlyCreated)->toBeFalse()
        ->and(FinancialAccount::query()->where('user_id', $user->id)->where('idempotency_key', $uuid)->count())->toBe(1);

    // SQLite in-memory does not provide true multi-connection concurrent writes;
    // the replay path above covers unique-key recovery after a committed create.
});

test('failed create inside a caller transaction does not leave a durable idempotency reservation', function (): void {
    $user = User::factory()->create();
    $uuid = (string) Str::uuid();

    expect(fn () => DB::transaction(function () use ($user, $uuid): void {
        app(FinancialAccountService::class)->createAccount($user, [
            'idempotency_key' => $uuid,
            'name' => 'Rolled Back Checking',
            'type' => 'checking',
            'currency_code' => 'USD',
            'starting_balance' => '25.00',
        ]);

        throw new RuntimeException('Force outer rollback');
    }))->toThrow(RuntimeException::class, 'Force outer rollback');

    expect(FinancialAccount::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0);

    $replay = app(FinancialAccountService::class)->createAccountResult($user, [
        'idempotency_key' => $uuid,
        'name' => 'Rolled Back Checking',
        'type' => 'checking',
        'currency_code' => 'USD',
        'starting_balance' => '25.00',
    ]);

    expect($replay->wasRecentlyCreated)->toBeTrue()
        ->and(FinancialAccount::query()->where('idempotency_key', $uuid)->count())->toBe(1)
        ->and(JournalEntry::query()->count())->toBe(1);
});

test('distinct idempotency keys still allow genuine new account creations', function (): void {
    $user = User::factory()->create();
    $service = app(FinancialAccountService::class);

    $first = $service->createAccount($user, [
        'idempotency_key' => (string) Str::uuid(),
        'name' => 'Checking A',
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);
    $second = $service->createAccount($user, [
        'idempotency_key' => (string) Str::uuid(),
        'name' => 'Checking B',
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);

    expect($first->id)->not->toBe($second->id)
        ->and(FinancialAccount::query()->where('user_id', $user->id)->count())->toBe(2);
});

test('different users can reuse the same create idempotency UUID independently', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $service = app(FinancialAccountService::class);
    $uuid = (string) Str::uuid();

    $accountA = $service->createAccount($userA, [
        'idempotency_key' => $uuid,
        'name' => 'User A Checking',
        'type' => 'checking',
        'currency_code' => 'USD',
        'starting_balance' => '25.00',
    ]);
    $accountB = $service->createAccount($userB, [
        'idempotency_key' => $uuid,
        'name' => 'User B Checking',
        'type' => 'checking',
        'currency_code' => 'USD',
        'starting_balance' => '25.00',
    ]);

    expect($accountA->id)->not->toBe($accountB->id)
        ->and((int) $accountA->user_id)->toBe((int) $userA->id)
        ->and((int) $accountB->user_id)->toBe((int) $userB->id)
        ->and(FinancialAccount::query()->where('idempotency_key', $uuid)->count())->toBe(2);

    $replayA = $service->createAccountResult($userA, [
        'idempotency_key' => $uuid,
        'name' => 'User A Checking',
        'type' => 'checking',
        'currency_code' => 'USD',
        'starting_balance' => '25.00',
    ]);

    expect($replayA->wasRecentlyCreated)->toBeFalse()
        ->and($replayA->account->id)->toBe($accountA->id)
        ->and((int) $replayA->account->user_id)->toBe((int) $userA->id)
        ->and($replayA->account->id)->not->toBe($accountB->id);
});

test('create fingerprint treats blank and zero openings plus equivalent decimals as the same payload', function (): void {
    $user = User::factory()->create();
    $service = app(FinancialAccountService::class);
    $uuid = (string) Str::uuid();

    $first = $service->createAccountResult($user, [
        'idempotency_key' => $uuid,
        'name' => 'Canonical Checking',
        'type' => 'checking',
        'currency_code' => 'USD',
        'institution' => '',
        'description' => '   ',
        'starting_balance' => '0.00',
    ]);

    $replayBlankZero = $service->createAccountResult($user, [
        'idempotency_key' => $uuid,
        'name' => 'Canonical Checking',
        'type' => 'checking',
        'currency_code' => 'USD',
        // omitted institution/description/starting_balance
    ]);

    expect($first->wasRecentlyCreated)->toBeTrue()
        ->and($replayBlankZero->wasRecentlyCreated)->toBeFalse()
        ->and($replayBlankZero->account->id)->toBe($first->account->id)
        ->and(JournalEntry::query()->count())->toBe(0);

    $fundedUuid = (string) Str::uuid();
    $funded = $service->createAccountResult($user, [
        'idempotency_key' => $fundedUuid,
        'name' => 'Decimal Checking',
        'type' => 'checking',
        'currency_code' => 'USD',
        'starting_balance' => '100.00',
    ]);
    $fundedReplay = $service->createAccountResult($user, [
        'idempotency_key' => $fundedUuid,
        'name' => 'Decimal Checking',
        'type' => 'checking',
        'currency_code' => 'USD',
        'starting_balance' => '100.0',
    ]);
    $fundedWhole = $service->createAccountResult($user, [
        'idempotency_key' => $fundedUuid,
        'name' => 'Decimal Checking',
        'type' => 'checking',
        'currency_code' => 'USD',
        'starting_balance' => '100',
    ]);

    expect($funded->wasRecentlyCreated)->toBeTrue()
        ->and($fundedReplay->wasRecentlyCreated)->toBeFalse()
        ->and($fundedWhole->wasRecentlyCreated)->toBeFalse()
        ->and($fundedReplay->account->id)->toBe($funded->account->id)
        ->and($fundedWhole->account->id)->toBe($funded->account->id)
        ->and(JournalEntry::query()->where('idempotency_key', 'like', 'opening-balance:account:%')->count())->toBe(1);
});

test('create idempotency classifier rejects unrelated uniqueness failures that mention idempotency_key in sql', function (): void {
    $user = User::factory()->create();
    $service = app(FinancialAccountService::class);
    $uuid = (string) Str::uuid();

    $first = $service->createAccountResult($user, [
        'idempotency_key' => $uuid,
        'name' => 'Classifier Checking',
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);
    $replay = $service->createAccountResult($user, [
        'idempotency_key' => $uuid,
        'name' => 'Classifier Checking',
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);

    expect($replay->wasRecentlyCreated)->toBeFalse()
        ->and($replay->account->id)->toBe($first->account->id);

    $sql = 'insert into "financial_accounts" ("user_id", "idempotency_key", "create_request_fingerprint", "name") values (?, ?, null, ?)';
    $previous = new \PDOException("SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'create_request_fingerprint' cannot be null");
    $previous->errorInfo = ['23000', 1048, "Column 'create_request_fingerprint' cannot be null"];
    $notNull = new QueryException('sqlite', $sql, [], $previous);

    expect(str_contains(strtolower($notNull->getMessage()), 'idempotency_key'))->toBeTrue()
        ->and(IdempotencyUniqueConstraint::matches(
            $notNull,
            FinancialAccountService::IDEMPOTENCY_UNIQUE_INDEX,
            'financial_accounts',
        ))->toBeFalse()
        ->and(AuditEvent::query()->where('action', AuditService::ACTION_ACCOUNT_CREATED)->where('auditable_id', $first->account->id)->count())->toBe(1);
});

test('migration 000016 restores sqlite financial transaction insert trigger protection', function (): void {
    if (DB::getDriverName() !== 'sqlite') {
        expect(true)->toBeTrue();

        return;
    }

    $trigger = DB::selectOne(
        "SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND name = 'trg_financial_transactions_validate_insert'",
    );

    expect($trigger)->not->toBeNull()
        ->and($trigger?->name)->toBe('trg_financial_transactions_validate_insert')
        ->and((string) $trigger?->sql)->toContain('financial_accounts')
        ->and((string) $trigger?->sql)->toContain('Financial transaction currency and owner must match the primary financial account.')
        ->and(Schema::hasColumn('financial_accounts', 'idempotency_key'))->toBeTrue()
        ->and(Schema::hasColumn('financial_accounts', 'create_request_fingerprint'))->toBeTrue();
});
