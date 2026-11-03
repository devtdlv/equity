<?php

namespace Tests\Integration\SqliteFile;

use App\DataTransferObjects\CreateFinancialTransactionInput;
use App\DataTransferObjects\PostingInstruction;
use App\DataTransferObjects\PostJournalEntryInput;
use App\DataTransferObjects\ReverseFinancialTransactionInput;
use App\Enums\CategoryType;
use App\Enums\FinancialTransactionType;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\ImmutableRecordException;
use App\Exceptions\LedgerIntegrityException;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\JournalEntry;
use App\Models\JournalPosting;
use App\Models\User;
use App\Services\AccountBalanceService;
use App\Services\CategoryService;
use App\Services\FinancialAccountService;
use App\Services\FinancialTransactionService;
use App\Services\LedgerPostingService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Test;

/**
 * Phase 2 file-based SQLite financial workflows.
 *
 * Shared disposable DB state across ordered tests (no RefreshDatabase / migrate:fresh).
 */
final class Phase2FinancialWorkflowsTest extends SqliteFileIntegrationTestCase
{
    private static ?int $userId = null;

    private static ?int $accountId = null;

    private static ?int $expenseCategoryId = null;

    private static ?int $transactionId = null;

    private static ?int $openingJournalId = null;

    private static string $accountCreateKey = '';

    private static string $transactionKey = '';

    private static int $openingMinor = 100_000; // ₱1,000.00

    private static int $expenseMinor = 25_000; // ₱250.00

    #[Test]
    public function test_1_financial_account_and_opening_balance(): void
    {
        $accountService = app(FinancialAccountService::class);
        $balanceService = app(AccountBalanceService::class);
        $categoryService = app(CategoryService::class);

        $user = User::factory()->create([
            'email' => 'phase2-'.Str::lower(Str::random(8)).'@example.test',
        ]);
        self::$userId = $user->id;
        self::$accountCreateKey = (string) Str::uuid();

        $created = $accountService->createAccountResult($user, [
            'name' => 'Phase2 Checking',
            'type' => 'checking',
            'currency_code' => 'PHP',
            'starting_balance' => '1000.00',
            'idempotency_key' => self::$accountCreateKey,
        ]);

        $this->assertTrue($created->wasRecentlyCreated);
        $account = $created->account->fresh(['ledgerAccount', 'currency']);
        self::$accountId = $account->id;
        self::$openingJournalId = $account->opening_journal_entry_id;

        $this->assertNotNull(self::$openingJournalId);
        $this->assertSame('PHP', $account->currency_code);
        $this->assertSame(2, (int) $account->currency->minor_unit_exponent);

        $balance = $balanceService->getBalanceForLedgerAccount($account->ledgerAccount);
        $this->assertSame(self::$openingMinor, $balance->amountMinor);
        $this->assertSame('PHP', $balance->currencyCode);

        $openingJournals = JournalEntry::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', 'opening-balance:account:'.$account->id)
            ->count();
        $this->assertSame(1, $openingJournals);

        $postingSum = JournalPosting::query()
            ->where('journal_entry_id', self::$openingJournalId)
            ->selectRaw('SUM(debit_amount_minor) as d, SUM(credit_amount_minor) as c')
            ->first();
        $this->assertSame((int) $postingSum->d, (int) $postingSum->c);
        $this->assertGreaterThan(0, (int) $postingSum->d);

        $replay = $accountService->createAccountResult($user, [
            'name' => 'Phase2 Checking',
            'type' => 'checking',
            'currency_code' => 'PHP',
            'starting_balance' => '1000.00',
            'idempotency_key' => self::$accountCreateKey,
        ]);
        $this->assertFalse($replay->wasRecentlyCreated);
        $this->assertSame($account->id, $replay->account->id);
        $this->assertSame(1, JournalEntry::query()
            ->where('idempotency_key', 'opening-balance:account:'.$account->id)
            ->count());
        $this->assertSame(1, FinancialAccount::query()->where('user_id', $user->id)->count());

        $category = $categoryService->createCategory($user, [
            'name' => 'Phase2 Groceries',
            'type' => CategoryType::Expense,
        ]);
        self::$expenseCategoryId = $category->id;
    }

    #[Test]
    #[Depends('test_1_financial_account_and_opening_balance')]
    public function test_2_transaction_posting_and_balance(): void
    {
        $user = User::query()->findOrFail(self::$userId);
        $account = FinancialAccount::query()->with('ledgerAccount')->findOrFail(self::$accountId);
        $transactionService = app(FinancialTransactionService::class);
        $balanceService = app(AccountBalanceService::class);
        $postingService = app(LedgerPostingService::class);

        self::$transactionKey = (string) Str::uuid();

        $created = $transactionService->createTransaction(new CreateFinancialTransactionInput(
            user: $user,
            type: FinancialTransactionType::Expense,
            financialAccountId: $account->id,
            amount: '250.00',
            entryDate: '2026-11-03',
            description: 'Phase2 grocery expense',
            idempotencyKey: self::$transactionKey,
            categoryId: self::$expenseCategoryId,
        ));

        $this->assertTrue($created->wasRecentlyCreated);
        self::$transactionId = $created->transaction->id;

        $journal = $created->journalEntry->load('postings');
        $debitTotal = (int) $journal->postings->sum('debit_amount_minor');
        $creditTotal = (int) $journal->postings->sum('credit_amount_minor');
        $this->assertSame($debitTotal, $creditTotal);
        $this->assertSame(self::$expenseMinor, $created->transaction->amount_minor);

        $expectedBalance = self::$openingMinor - self::$expenseMinor;
        $this->assertSame(
            $expectedBalance,
            $balanceService->getBalanceForLedgerAccount($account->ledgerAccount()->first())->amountMinor,
        );

        $other = FinancialAccount::query()->with('ledgerAccount')->findOrFail(self::$accountId);
        try {
            $postingService->post(new PostJournalEntryInput(
                user: $user,
                currencyCode: 'PHP',
                entryDate: '2026-11-03',
                description: 'Unbalanced must fail',
                idempotencyKey: 'phase2-unbalanced-'.Str::uuid(),
                postings: [
                    new PostingInstruction(
                        ledgerAccountId: $other->ledgerAccount->id,
                        debitAmountMinor: 100,
                        creditAmountMinor: 0,
                    ),
                ],
            ));
            $this->fail('Expected LedgerIntegrityException for unbalanced journal.');
        } catch (LedgerIntegrityException) {
            // expected
        }
    }

    #[Test]
    #[Depends('test_2_transaction_posting_and_balance')]
    public function test_3_transaction_reversal(): void
    {
        $user = User::query()->findOrFail(self::$userId);
        $account = FinancialAccount::query()->with('ledgerAccount')->findOrFail(self::$accountId);
        $transactionService = app(FinancialTransactionService::class);
        $balanceService = app(AccountBalanceService::class);

        $original = FinancialTransaction::query()->findOrFail(self::$transactionId);
        $originalJournalId = $original->journal_entry_id;
        $reversalKey = (string) Str::uuid();

        $reversed = $transactionService->reverseTransaction(new ReverseFinancialTransactionInput(
            user: $user,
            originalTransactionId: $original->id,
            idempotencyKey: $reversalKey,
            reason: 'Phase2 reversal',
        ));

        $this->assertTrue($reversed->wasRecentlyCreated);
        $this->assertNotNull(JournalEntry::query()->find($originalJournalId));
        $this->assertNotSame($originalJournalId, $reversed->reversalJournalEntry->id);

        $this->assertSame(
            self::$openingMinor,
            $balanceService->getBalanceForLedgerAccount($account->ledgerAccount()->first())->amountMinor,
        );

        $replay = $transactionService->reverseTransaction(new ReverseFinancialTransactionInput(
            user: $user,
            originalTransactionId: $original->id,
            idempotencyKey: $reversalKey,
            reason: 'Phase2 reversal',
        ));
        $this->assertFalse($replay->wasRecentlyCreated);
        $this->assertSame($reversed->reversalTransaction->id, $replay->reversalTransaction->id);

        $this->assertSame(
            self::$openingMinor,
            $balanceService->getBalanceForLedgerAccount($account->fresh()->ledgerAccount)->amountMinor,
        );
    }

    #[Test]
    #[Depends('test_3_transaction_reversal')]
    public function test_4_idempotency(): void
    {
        $user = User::query()->findOrFail(self::$userId);
        $accountService = app(FinancialAccountService::class);
        $transactionService = app(FinancialTransactionService::class);

        $accountReplay = $accountService->createAccountResult($user, [
            'name' => 'Phase2 Checking',
            'type' => 'checking',
            'currency_code' => 'PHP',
            'starting_balance' => '1000.00',
            'idempotency_key' => self::$accountCreateKey,
        ]);
        $this->assertFalse($accountReplay->wasRecentlyCreated);

        $this->expectException(IdempotencyConflictException::class);
        $accountService->createAccountResult($user, [
            'name' => 'Phase2 Checking CONFLICT',
            'type' => 'checking',
            'currency_code' => 'PHP',
            'starting_balance' => '1000.00',
            'idempotency_key' => self::$accountCreateKey,
        ]);
    }

    #[Test]
    #[Depends('test_4_idempotency')]
    public function test_4b_transaction_idempotency_conflict_and_replay(): void
    {
        $user = User::query()->findOrFail(self::$userId);
        $account = FinancialAccount::query()->findOrFail(self::$accountId);
        $transactionService = app(FinancialTransactionService::class);

        // Original expense was reversed; replay of the original create key still returns the existing txn.
        $replay = $transactionService->createTransaction(new CreateFinancialTransactionInput(
            user: $user,
            type: FinancialTransactionType::Expense,
            financialAccountId: $account->id,
            amount: '250.00',
            entryDate: '2026-11-03',
            description: 'Phase2 grocery expense',
            idempotencyKey: self::$transactionKey,
            categoryId: self::$expenseCategoryId,
        ));
        $this->assertFalse($replay->wasRecentlyCreated);
        $this->assertSame(self::$transactionId, $replay->transaction->id);

        $this->expectException(IdempotencyConflictException::class);
        $transactionService->createTransaction(new CreateFinancialTransactionInput(
            user: $user,
            type: FinancialTransactionType::Expense,
            financialAccountId: $account->id,
            amount: '251.00',
            entryDate: '2026-11-03',
            description: 'Phase2 grocery expense CONFLICT',
            idempotencyKey: self::$transactionKey,
            categoryId: self::$expenseCategoryId,
        ));
    }

    #[Test]
    #[Depends('test_4b_transaction_idempotency_conflict_and_replay')]
    public function test_5_persistence_after_reconnect(): void
    {
        $path = SqliteFileIsolation::approvedDatabasePath();
        $userId = self::$userId;
        $accountId = self::$accountId;
        $transactionId = self::$transactionId;
        $openingJournalId = self::$openingJournalId;
        $expectedBalance = self::$openingMinor;

        $user = User::query()->findOrFail($userId);
        $account = FinancialAccount::query()->with('ledgerAccount')->findOrFail($accountId);
        $txn = FinancialTransaction::query()->findOrFail($transactionId);
        $this->assertNotNull(JournalEntry::query()->find($openingJournalId));
        $this->assertSame($user->id, $account->user_id);
        $this->assertSame($account->id, $txn->financial_account_id);

        $balanceBefore = app(AccountBalanceService::class)
            ->getBalanceForLedgerAccount($account->ledgerAccount)
            ->amountMinor;
        $this->assertSame($expectedBalance, $balanceBefore);

        $pragmaBefore = [
            'journal_mode' => DB::selectOne('PRAGMA journal_mode'),
            'busy_timeout' => DB::selectOne('PRAGMA busy_timeout'),
            'foreign_keys' => DB::selectOne('PRAGMA foreign_keys'),
        ];

        DB::purge('sqlite');
        DB::disconnect('sqlite');

        // New connection to the same disposable file.
        config(['database.connections.sqlite.database' => $path]);
        DB::reconnect('sqlite');

        $this->assertSqliteFileConnectionPublic();

        $accountAfter = FinancialAccount::query()->with('ledgerAccount')->findOrFail($accountId);
        $txnAfter = FinancialTransaction::query()->findOrFail($transactionId);
        $this->assertNotNull(JournalEntry::query()->find($openingJournalId));
        $this->assertSame($accountAfter->id, $txnAfter->financial_account_id);

        $balanceAfter = app(AccountBalanceService::class)
            ->getBalanceForLedgerAccount($accountAfter->ledgerAccount)
            ->amountMinor;
        $this->assertSame($expectedBalance, $balanceAfter);

        $journalMode = strtolower((string) (DB::selectOne('PRAGMA journal_mode')->journal_mode ?? ''));
        $busyTimeout = (int) (DB::selectOne('PRAGMA busy_timeout')->timeout ?? DB::selectOne('PRAGMA busy_timeout')->busy_timeout ?? -1);
        $foreignKeys = (int) (DB::selectOne('PRAGMA foreign_keys')->foreign_keys ?? 0);

        $this->assertSame('wal', $journalMode);
        $this->assertSame(5000, $busyTimeout);
        $this->assertSame(1, $foreignKeys);

        // Keep pragma evidence available for the final report via failure messages if needed.
        $this->assertNotNull($pragmaBefore['journal_mode']);
    }

    #[Test]
    #[Depends('test_5_persistence_after_reconnect')]
    public function test_6_foreign_keys_and_financial_integrity(): void
    {
        $user = User::query()->findOrFail(self::$userId);
        $account = FinancialAccount::query()->with('ledgerAccount')->findOrFail(self::$accountId);
        $postingService = app(LedgerPostingService::class);

        $fk = DB::selectOne('PRAGMA foreign_keys');
        $this->assertSame(1, (int) ($fk->foreign_keys ?? 0));

        $beforeAccounts = FinancialAccount::query()->count();
        $beforeJournals = JournalEntry::query()->count();
        $beforePostings = JournalPosting::query()->count();

        try {
            DB::table('ledger_accounts')->insert([
                'user_id' => $user->id,
                'code' => 'BOGUS-FK-'.Str::random(4),
                'name' => 'Bogus',
                'classification' => 'asset',
                'currency_code' => 'ZZZ',
                'financial_account_id' => null,
                'is_system' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected foreign key rejection for unknown currency_code.');
        } catch (QueryException) {
            // expected
        }

        $this->assertSame($beforeAccounts, FinancialAccount::query()->count());
        $this->assertSame($beforeJournals, JournalEntry::query()->count());
        $this->assertSame($beforePostings, JournalPosting::query()->count());

        try {
            DB::table('journal_postings')->insert([
                'journal_entry_id' => self::$openingJournalId,
                'ledger_account_id' => $account->ledgerAccount->id,
                'debit_amount_minor' => 1,
                'credit_amount_minor' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected CHECK constraint rejection for debit and credit both nonzero.');
        } catch (QueryException) {
            // expected
        }

        $entry = JournalEntry::query()->findOrFail(self::$openingJournalId);
        $posting = $entry->postings()->firstOrFail();

        try {
            $entry->update(['description' => 'tamper']);
            $this->fail('Expected ImmutableRecordException for journal update.');
        } catch (ImmutableRecordException) {
            // expected
        }

        try {
            DB::table('journal_entries')->where('id', $entry->id)->update(['description' => 'raw tamper']);
            $this->fail('Expected trigger rejection for raw journal update.');
        } catch (QueryException) {
            // expected
        }

        try {
            DB::table('financial_accounts')->where('id', $account->id)->update([
                'opening_journal_entry_id' => 999999999,
            ]);
            $this->fail('Expected foreign key rejection for invalid opening_journal_entry_id.');
        } catch (QueryException) {
            // expected
        }

        $accountService = app(FinancialAccountService::class);
        $beforeAccountCount = FinancialAccount::query()->where('user_id', $user->id)->count();
        try {
            $accountService->createAccountResult($user, [
                'name' => 'Phase2 Checking CONFLICT 2',
                'type' => 'savings',
                'currency_code' => 'PHP',
                'starting_balance' => '50.00',
                'idempotency_key' => self::$accountCreateKey,
            ]);
            $this->fail('Expected IdempotencyConflictException.');
        } catch (IdempotencyConflictException) {
            // expected
        }
        $this->assertSame(
            $beforeAccountCount,
            FinancialAccount::query()->where('user_id', $user->id)->count(),
        );

        // Partial-failure rollback: mid-posting exception leaves no new journal/postings.
        $counterparty = app(FinancialAccountService::class)->createAccount($user, [
            'name' => 'Phase2 Savings Rollback Probe',
            'type' => 'savings',
            'currency_code' => 'PHP',
            'idempotency_key' => (string) Str::uuid(),
        ])->load('ledgerAccount');

        $shouldFail = true;
        JournalPosting::creating(function () use (&$shouldFail): void {
            if ($shouldFail) {
                $shouldFail = false;
                throw new \RuntimeException('Phase2 simulated mid-posting failure');
            }
        });

        $journalsBefore = JournalEntry::query()->count();
        $postingsBefore = JournalPosting::query()->count();

        try {
            $postingService->post(new PostJournalEntryInput(
                user: $user,
                currencyCode: 'PHP',
                entryDate: '2026-11-03',
                description: 'Phase2 rollback probe',
                idempotencyKey: 'phase2-rollback-'.(string) Str::uuid(),
                postings: [
                    new PostingInstruction(
                        ledgerAccountId: $account->ledgerAccount->id,
                        debitAmountMinor: 500,
                    ),
                    new PostingInstruction(
                        ledgerAccountId: $counterparty->ledgerAccount->id,
                        creditAmountMinor: 500,
                    ),
                ],
            ));
            $this->fail('Expected simulated mid-posting failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Phase2 simulated mid-posting failure', $e->getMessage());
        }

        $this->assertSame($journalsBefore, JournalEntry::query()->count());
        $this->assertSame($postingsBefore, JournalPosting::query()->count());
        $this->assertSame(
            self::$openingMinor,
            app(AccountBalanceService::class)->getBalanceForLedgerAccount($account->fresh()->ledgerAccount)->amountMinor,
        );

        // Silence unused variable in static analysis for posting immutability subject.
        $this->assertNotNull($posting->id);
    }

    /**
     * Re-run the file guard after reconnect without going through refreshApplication.
     */
    private function assertSqliteFileConnectionPublic(): void
    {
        $default = $this->app['config']->get('database.default');
        $result = \Tests\Support\SqliteFileConnectionGuard::evaluate([
            'default' => $default,
            'connections' => $this->app['config']->get('database.connections'),
            'approved_database_path' => SqliteFileIsolation::approvedDatabasePath(),
            'repository_root' => SqliteFileIsolation::repositoryRoot(),
            'forbidden_database_path' => SqliteFileIsolation::forbiddenDatabasePath(),
            'run_token' => SqliteFileIsolation::runToken(),
            'expected_database_basename' => SqliteFileIsolation::databaseBasenameForToken(SqliteFileIsolation::runToken()),
            'transacting_connections' => [$default],
            'config_loaded_from_cache' => $this->app->configurationIsCached(),
            'resolved_config_cache_exists' => is_file($this->app->getCachedConfigPath()),
            'default_config_cache_exists' => is_file($this->app->bootstrapPath('cache/config.php')),
        ]);

        $this->assertTrue($result['ok'], \Tests\Support\SqliteFileConnectionGuard::failureMessage($result));
    }
}
