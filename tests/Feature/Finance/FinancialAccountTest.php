<?php

use App\DataTransferObjects\PostingInstruction;
use App\DataTransferObjects\PostJournalEntryInput;
use App\Enums\FinancialAccountType;
use App\Enums\LedgerAccountClassification;
use App\Exceptions\ImmutableRecordException;
use App\Exceptions\LedgerIntegrityException;
use App\Models\FinancialAccount;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\FinancialAccountService;
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

    expect(fn () => $account->delete())
        ->toThrow(LedgerIntegrityException::class);
});
