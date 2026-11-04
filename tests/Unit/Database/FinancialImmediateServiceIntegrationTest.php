<?php

namespace Tests\Unit\Database;

use App\DataTransferObjects\CreateFinancialTransactionInput;
use App\DataTransferObjects\PostOpeningBalanceInput;
use App\DataTransferObjects\ReverseFinancialTransactionInput;
use App\Database\SQLite\EquitySQLiteConnection;
use App\Database\SQLite\ImmediateAwareSqlitePdo;
use App\Enums\CategoryType;
use App\Enums\FinancialTransactionType;
use App\Exceptions\FinancialImmediateTransactionConflictException;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\AccountBalanceService;
use App\Services\AuditService;
use App\Services\CategoryService;
use App\Services\FinancialAccountService;
use App\Services\FinancialTransactionService;
use App\Services\LedgerPostingService;
use App\Services\OpeningBalanceService;
use App\Services\OpeningEquityAccountService;
use App\Support\FinancialDatabaseTransaction;
use RuntimeException;
use Database\Seeders\CurrencySeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4C.2 service integration against a disposable file SQLite database.
 * Does not extend Tests\TestCase (avoids :memory: guard + RefreshDatabase DEFERRED wrap).
 * Never touches database/database.sqlite.
 */
final class FinancialImmediateServiceIntegrationTest extends TestCase
{
    private string $path;

    private string $forbidden;

    protected function setUp(): void
    {
        parent::setUp();

        $token = bin2hex(random_bytes(8));
        $this->path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'equity-4c2-svc-'.$token.'.sqlite';
        if (file_exists($this->path)) {
            $this->fail('Refusing to reuse an existing disposable path.');
        }
        touch($this->path);

        $repoRoot = dirname(__DIR__, 3);
        $this->forbidden = $repoRoot.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite';
        $this->assertNotSame(
            realpath($this->path) ?: $this->path,
            realpath($this->forbidden) ?: $this->forbidden,
        );

        $app = require $repoRoot.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php';
        $app->make(Kernel::class)->bootstrap();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->path,
            'database.connections.sqlite.busy_timeout' => 5000,
            'database.connections.sqlite.foreign_key_constraints' => true,
            'database.connections.sqlite.journal_mode' => 'wal',
            'equity.sqlite.financial_immediate' => false,
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Artisan::call('migrate', ['--force' => true]);
        Artisan::call('db:seed', ['--class' => CurrencySeeder::class, '--force' => true]);
    }

    protected function tearDown(): void
    {
        try {
            DB::disconnect('sqlite');
        } catch (\Throwable) {
        }

        restore_exception_handler();
        restore_error_handler();

        config(['equity.sqlite.financial_immediate' => false]);

        gc_collect_cycles();
        foreach ([$this->path, $this->path.'-wal', $this->path.'-shm', $this->path.'-journal'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    /**
     * @return array{user: User, accountId: int, categoryId: int, ledgerAccountId: int, openingMinor: int}
     */
    private function fixtures(bool $withStartingBalance = true): array
    {
        $user = User::factory()->create();
        $payload = [
            'name' => '4C2 Checking',
            'type' => 'checking',
            'currency_code' => 'PHP',
            'idempotency_key' => (string) Str::uuid(),
        ];
        if ($withStartingBalance) {
            $payload['starting_balance'] = '1000.00';
        }

        $account = app(FinancialAccountService::class)->createAccountResult($user, $payload)
            ->account
            ->fresh(['ledgerAccount']);

        $category = app(CategoryService::class)->createCategory($user, [
            'name' => '4C2 Groceries',
            'type' => CategoryType::Expense,
        ]);

        return [
            'user' => $user,
            'accountId' => (int) $account->id,
            'categoryId' => (int) $category->id,
            'ledgerAccountId' => (int) $account->ledgerAccount->id,
            'openingMinor' => $withStartingBalance ? 100_000 : 0,
        ];
    }

    private function todayUtc(): string
    {
        return now('UTC')->format('Y-m-d');
    }

    private function assertBalancedAndIntegrity(): void
    {
        $rows = JournalEntry::query()
            ->leftJoin('journal_postings', 'journal_postings.journal_entry_id', '=', 'journal_entries.id')
            ->groupBy('journal_entries.id')
            ->selectRaw(
                'journal_entries.id, COALESCE(SUM(journal_postings.debit_amount_minor), 0) as debits, '
                .'COALESCE(SUM(journal_postings.credit_amount_minor), 0) as credits',
            )
            ->get();

        foreach ($rows as $row) {
            $this->assertSame((int) $row->credits, (int) $row->debits, 'Unbalanced journal '.$row->id);
        }

        $integrity = DB::selectOne('PRAGMA integrity_check');
        $value = is_object($integrity) ? (string) array_values((array) $integrity)[0] : '';
        $this->assertSame('ok', strtolower($value));
    }

    public static function flagMatrix(): array
    {
        return [
            'flag off' => [false],
            'flag on' => [true],
        ];
    }

    #[Test]
    public function default_flag_is_off_and_writes_use_deferred_begin(): void
    {
        $this->assertFalse((bool) config('equity.sqlite.financial_immediate'));

        /** @var EquitySQLiteConnection $connection */
        $connection = DB::connection();
        $this->assertInstanceOf(EquitySQLiteConnection::class, $connection);

        $mode = null;
        FinancialDatabaseTransaction::run(function () use ($connection, &$mode): void {
            $mode = $connection->outermostBeginMode();
        });
        $this->assertSame(EquitySQLiteConnection::MODE_DEFERRED, $mode);

        $fixtures = $this->fixtures();
        $created = app(FinancialTransactionService::class)->createTransaction(new CreateFinancialTransactionInput(
            user: $fixtures['user'],
            type: FinancialTransactionType::Expense,
            financialAccountId: $fixtures['accountId'],
            amount: '10.00',
            entryDate: $this->todayUtc(),
            description: 'Flag off expense',
            idempotencyKey: (string) Str::uuid(),
            categoryId: $fixtures['categoryId'],
        ));
        $reversed = app(FinancialTransactionService::class)->reverseTransaction(new ReverseFinancialTransactionInput(
            user: $fixtures['user'],
            originalTransactionId: (int) $created->transaction->id,
            idempotencyKey: (string) Str::uuid(),
            reason: 'undo',
        ));

        $this->assertTrue($created->wasRecentlyCreated);
        $this->assertTrue($reversed->wasRecentlyCreated);
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertNull($connection->outermostBeginMode());
        $this->assertBalancedAndIntegrity();
    }

    #[Test]
    #[DataProvider('flagMatrix')]
    public function create_reverse_account_opening_and_equity_preserve_balances(bool $immediate): void
    {
        config(['equity.sqlite.financial_immediate' => $immediate]);

        /** @var EquitySQLiteConnection $connection */
        $connection = DB::connection();
        $this->assertInstanceOf(ImmediateAwareSqlitePdo::class, $connection->getPdo());

        $expected = $immediate
            ? EquitySQLiteConnection::MODE_IMMEDIATE
            : EquitySQLiteConnection::MODE_DEFERRED;

        $observed = null;
        FinancialDatabaseTransaction::run(function () use ($connection, &$observed): void {
            $observed = $connection->outermostBeginMode();
            if ($observed === EquitySQLiteConnection::MODE_IMMEDIATE) {
                $this->assertTrue($connection->getPdo()->immediateTransactionOpen());
            }
        });
        $this->assertSame($expected, $observed);

        $user = User::factory()->create();
        $currency = Currency::query()->where('code', 'PHP')->firstOrFail();
        $equity = app(OpeningEquityAccountService::class)->resolveOrCreate($user, $currency);
        $this->assertSame('EQ-OPEN-PHP', $equity->code);
        $this->assertTrue($equity->is_system);

        $account = app(FinancialAccountService::class)->createAccountResult($user, [
            'name' => 'Matrix Checking',
            'type' => 'checking',
            'currency_code' => 'PHP',
            'starting_balance' => '100.00',
            'idempotency_key' => (string) Str::uuid(),
        ])->account->fresh(['ledgerAccount']);
        $this->assertNotNull($account->opening_journal_entry_id);

        $category = app(CategoryService::class)->createCategory($user, [
            'name' => 'Matrix Cat',
            'type' => CategoryType::Expense,
        ]);

        $created = app(FinancialTransactionService::class)->createTransaction(new CreateFinancialTransactionInput(
            user: $user,
            type: FinancialTransactionType::Expense,
            financialAccountId: (int) $account->id,
            amount: '25.00',
            entryDate: $this->todayUtc(),
            description: 'Matrix expense',
            idempotencyKey: (string) Str::uuid(),
            categoryId: (int) $category->id,
        ));

        $categoryLedger = app(FinancialTransactionService::class)->resolveOrCreateCategoryLedgerAccount(
            $user,
            $category,
            'PHP',
        );
        $this->assertSame((int) $category->id, (int) $categoryLedger->category_id);

        $reversed = app(FinancialTransactionService::class)->reverseTransaction(new ReverseFinancialTransactionInput(
            user: $user,
            originalTransactionId: (int) $created->transaction->id,
            idempotencyKey: (string) Str::uuid(),
            reason: 'matrix undo',
        ));

        $balance = app(AccountBalanceService::class)
            ->getBalanceForLedgerAccount($account->ledgerAccount)
            ->amountMinor;

        $this->assertSame(10_000, $balance);
        $this->assertTrue($reversed->wasRecentlyCreated);
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertNull($connection->outermostBeginMode());
        $this->assertBalancedAndIntegrity();
    }

    #[Test]
    public function flag_on_under_deferred_outer_fails_closed_without_partial_writes(): void
    {
        config(['equity.sqlite.financial_immediate' => true]);
        $fixtures = $this->fixtures();

        try {
            DB::transaction(function () use ($fixtures): void {
                app(FinancialTransactionService::class)->createTransaction(new CreateFinancialTransactionInput(
                    user: $fixtures['user'],
                    type: FinancialTransactionType::Expense,
                    financialAccountId: $fixtures['accountId'],
                    amount: '5.00',
                    entryDate: $this->todayUtc(),
                    description: 'Must fail closed',
                    idempotencyKey: (string) Str::uuid(),
                    categoryId: $fixtures['categoryId'],
                ));
            });
            $this->fail('Expected FinancialImmediateTransactionConflictException');
        } catch (FinancialImmediateTransactionConflictException) {
            // expected
        }

        $this->assertSame(0, JournalEntry::query()->where('description', 'Must fail closed')->count());
        $this->assertBalancedAndIntegrity();
    }

    #[Test]
    public function flag_on_nested_under_immediate_outer_uses_savepoints(): void
    {
        config(['equity.sqlite.financial_immediate' => true]);
        /** @var EquitySQLiteConnection $connection */
        $connection = DB::connection();
        $fixtures = $this->fixtures();

        $levels = [];
        $result = FinancialDatabaseTransaction::run(function () use ($connection, $fixtures, &$levels) {
            $levels[] = [$connection->transactionLevel(), $connection->outermostBeginMode()];
            $this->assertSame(EquitySQLiteConnection::MODE_IMMEDIATE, $connection->outermostBeginMode());

            $created = app(FinancialTransactionService::class)->createTransaction(new CreateFinancialTransactionInput(
                user: $fixtures['user'],
                type: FinancialTransactionType::Expense,
                financialAccountId: $fixtures['accountId'],
                amount: '7.50',
                entryDate: $this->todayUtc(),
                description: 'Nested under IMMEDIATE',
                idempotencyKey: (string) Str::uuid(),
                categoryId: $fixtures['categoryId'],
            ));
            $levels[] = [$connection->transactionLevel(), $connection->outermostBeginMode()];

            return $created;
        });

        $this->assertTrue($result->wasRecentlyCreated);
        $this->assertSame([1, EquitySQLiteConnection::MODE_IMMEDIATE], $levels[0]);
        $this->assertSame([1, EquitySQLiteConnection::MODE_IMMEDIATE], $levels[1]);
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertBalancedAndIntegrity();
    }

    #[Test]
    #[DataProvider('flagMatrix')]
    public function standalone_opening_balance_and_equity(bool $immediate): void
    {
        config(['equity.sqlite.financial_immediate' => $immediate]);
        $fixtures = $this->fixtures(withStartingBalance: false);
        $user = $fixtures['user'];

        $currency = Currency::query()->where('code', 'PHP')->firstOrFail();
        $equity = app(OpeningEquityAccountService::class)->resolveOrCreate($user, $currency);
        $this->assertSame('EQ-OPEN-PHP', $equity->code);

        $key = 'opening-standalone-'.(string) Str::uuid();
        $posted = app(OpeningBalanceService::class)->postForAccount(new PostOpeningBalanceInput(
            user: $user,
            financialAccountId: $fixtures['accountId'],
            amountMinor: 25_000,
            entryDate: $this->todayUtc(),
            idempotencyKey: $key,
        ));
        $this->assertTrue($posted->wasRecentlyCreated);
        $this->assertSame(25_000, $posted->amountMinor);
        $this->assertNotNull($posted->journalEntry);

        $replay = app(OpeningBalanceService::class)->postForAccount(new PostOpeningBalanceInput(
            user: $user,
            financialAccountId: $fixtures['accountId'],
            amountMinor: 25_000,
            entryDate: $this->todayUtc(),
            idempotencyKey: $key,
        ));
        $this->assertFalse($replay->wasRecentlyCreated);

        $balance = app(AccountBalanceService::class)
            ->getBalanceForLedgerAccount(LedgerAccount::query()->findOrFail($fixtures['ledgerAccountId']))
            ->amountMinor;
        $this->assertSame(25_000, $balance);
        $this->assertBalancedAndIntegrity();
    }

    #[Test]
    public function shared_reversal_idempotency_replay_with_flag_on(): void
    {
        config(['equity.sqlite.financial_immediate' => true]);
        $fixtures = $this->fixtures();
        $txService = app(FinancialTransactionService::class);

        $created = $txService->createTransaction(new CreateFinancialTransactionInput(
            user: $fixtures['user'],
            type: FinancialTransactionType::Expense,
            financialAccountId: $fixtures['accountId'],
            amount: '12.00',
            entryDate: $this->todayUtc(),
            description: 'Idempotent reverse target',
            idempotencyKey: (string) Str::uuid(),
            categoryId: $fixtures['categoryId'],
        ));

        $reversalKey = (string) Str::uuid();
        $first = $txService->reverseTransaction(new ReverseFinancialTransactionInput(
            user: $fixtures['user'],
            originalTransactionId: (int) $created->transaction->id,
            idempotencyKey: $reversalKey,
            reason: 'shared key',
        ));
        $second = $txService->reverseTransaction(new ReverseFinancialTransactionInput(
            user: $fixtures['user'],
            originalTransactionId: (int) $created->transaction->id,
            idempotencyKey: $reversalKey,
            reason: 'shared key',
        ));

        $this->assertTrue($first->wasRecentlyCreated);
        $this->assertFalse($second->wasRecentlyCreated);
        $this->assertSame($first->reversalTransaction->id, $second->reversalTransaction->id);

        $balance = app(AccountBalanceService::class)
            ->getBalanceForLedgerAccount(LedgerAccount::query()->findOrFail($fixtures['ledgerAccountId']))
            ->amountMinor;
        $this->assertSame($fixtures['openingMinor'], $balance);
        $this->assertBalancedAndIntegrity();
    }

    #[Test]
    public function flag_on_deferred_outer_opening_balance_fails_closed(): void
    {
        config(['equity.sqlite.financial_immediate' => true]);
        $fixtures = $this->fixtures(withStartingBalance: false);
        $accountsBefore = FinancialAccount::query()->count();
        $equityBefore = LedgerAccount::query()->where('code', 'EQ-OPEN-PHP')->count();
        $journalsBefore = JournalEntry::query()->count();

        try {
            DB::transaction(function () use ($fixtures): void {
                app(OpeningBalanceService::class)->postForAccount(new PostOpeningBalanceInput(
                    user: $fixtures['user'],
                    financialAccountId: $fixtures['accountId'],
                    amountMinor: 10_000,
                    entryDate: $this->todayUtc(),
                    idempotencyKey: 'opening-deferred-blocked-'.(string) Str::uuid(),
                ));
            });
            $this->fail('Expected FinancialImmediateTransactionConflictException');
        } catch (FinancialImmediateTransactionConflictException) {
            // expected
        }

        $this->assertSame($accountsBefore, FinancialAccount::query()->count());
        $this->assertSame($equityBefore, LedgerAccount::query()->where('code', 'EQ-OPEN-PHP')->count());
        $this->assertSame($journalsBefore, JournalEntry::query()->count());
        $this->assertNull(
            FinancialAccount::query()->find($fixtures['accountId'])?->opening_journal_entry_id,
        );
        $this->assertBalancedAndIntegrity();
    }

    #[Test]
    public function flag_on_deferred_outer_opening_equity_fails_closed(): void
    {
        config(['equity.sqlite.financial_immediate' => true]);
        $user = User::factory()->create();
        $currency = Currency::query()->where('code', 'PHP')->firstOrFail();
        $equityBefore = LedgerAccount::query()
            ->where('user_id', $user->id)
            ->where('code', OpeningEquityAccountService::canonicalCode('PHP'))
            ->count();

        try {
            DB::transaction(function () use ($user, $currency): void {
                app(OpeningEquityAccountService::class)->resolveOrCreate($user, $currency);
            });
            $this->fail('Expected FinancialImmediateTransactionConflictException');
        } catch (FinancialImmediateTransactionConflictException) {
            // expected
        }

        $this->assertSame(
            $equityBefore,
            LedgerAccount::query()
                ->where('user_id', $user->id)
                ->where('code', OpeningEquityAccountService::canonicalCode('PHP'))
                ->count(),
        );
        $this->assertBalancedAndIntegrity();
    }

    #[Test]
    public function flag_on_immediate_outer_opening_services_join_without_extra_begin(): void
    {
        config(['equity.sqlite.financial_immediate' => true]);
        /** @var EquitySQLiteConnection $connection */
        $connection = DB::connection();
        $fixtures = $this->fixtures(withStartingBalance: false);
        $currency = Currency::query()->where('code', 'PHP')->firstOrFail();

        FinancialDatabaseTransaction::run(function () use ($connection, $fixtures, $currency): void {
            $this->assertSame(EquitySQLiteConnection::MODE_IMMEDIATE, $connection->outermostBeginMode());
            $levelBefore = $connection->transactionLevel();

            $equity = app(OpeningEquityAccountService::class)->resolveOrCreate($fixtures['user'], $currency);
            $this->assertSame($levelBefore, $connection->transactionLevel());
            $this->assertSame(EquitySQLiteConnection::MODE_IMMEDIATE, $connection->outermostBeginMode());
            $this->assertSame('EQ-OPEN-PHP', $equity->code);

            $posted = app(OpeningBalanceService::class)->postForAccount(new PostOpeningBalanceInput(
                user: $fixtures['user'],
                financialAccountId: $fixtures['accountId'],
                amountMinor: 15_000,
                entryDate: $this->todayUtc(),
                idempotencyKey: 'opening-immediate-join-'.(string) Str::uuid(),
            ));
            $this->assertSame($levelBefore, $connection->transactionLevel());
            $this->assertSame(EquitySQLiteConnection::MODE_IMMEDIATE, $connection->outermostBeginMode());
            $this->assertTrue($posted->wasRecentlyCreated);
        });

        $this->assertSame(0, $connection->transactionLevel());
        $this->assertBalancedAndIntegrity();
    }

    #[Test]
    public function flag_off_deferred_outer_opening_services_still_join(): void
    {
        config(['equity.sqlite.financial_immediate' => false]);
        $fixtures = $this->fixtures(withStartingBalance: false);
        $currency = Currency::query()->where('code', 'PHP')->firstOrFail();

        DB::transaction(function () use ($fixtures, $currency): void {
            $equity = app(OpeningEquityAccountService::class)->resolveOrCreate($fixtures['user'], $currency);
            $this->assertSame('EQ-OPEN-PHP', $equity->code);

            $posted = app(OpeningBalanceService::class)->postForAccount(new PostOpeningBalanceInput(
                user: $fixtures['user'],
                financialAccountId: $fixtures['accountId'],
                amountMinor: 8_000,
                entryDate: $this->todayUtc(),
                idempotencyKey: 'opening-deferred-join-'.(string) Str::uuid(),
            ));
            $this->assertTrue($posted->wasRecentlyCreated);
        });

        $this->assertNotNull(
            FinancialAccount::query()->find($fixtures['accountId'])?->fresh()->opening_journal_entry_id,
        );
        $this->assertBalancedAndIntegrity();
    }

    #[Test]
    public function flag_on_deferred_outer_category_ledger_resolve_fails_closed(): void
    {
        config(['equity.sqlite.financial_immediate' => true]);
        $fixtures = $this->fixtures();
        $category = app(CategoryService::class)->createCategory($fixtures['user'], [
            'name' => 'Blocked Category Ledger',
            'type' => CategoryType::Expense,
        ]);
        $before = LedgerAccount::query()->where('category_id', $category->id)->count();

        try {
            DB::transaction(function () use ($fixtures, $category): void {
                app(FinancialTransactionService::class)->resolveOrCreateCategoryLedgerAccount(
                    $fixtures['user'],
                    $category,
                    'PHP',
                );
            });
            $this->fail('Expected FinancialImmediateTransactionConflictException');
        } catch (FinancialImmediateTransactionConflictException) {
            // expected
        }

        $this->assertSame($before, LedgerAccount::query()->where('category_id', $category->id)->count());
        $this->assertBalancedAndIntegrity();
    }

    #[Test]
    public function account_create_rolls_back_when_opening_posting_fails(): void
    {
        config(['equity.sqlite.financial_immediate' => true]);
        $user = User::factory()->create();
        $accountsBefore = FinancialAccount::query()->where('user_id', $user->id)->count();
        $ledgersBefore = LedgerAccount::query()->where('user_id', $user->id)->count();
        $journalsBefore = JournalEntry::query()->where('user_id', $user->id)->count();

        $failingOpening = new class(
            app(LedgerPostingService::class),
            app(OpeningEquityAccountService::class),
            app(AuditService::class),
        ) extends OpeningBalanceService
        {
            public function postForAccount(PostOpeningBalanceInput $input): \App\DataTransferObjects\PostedOpeningBalanceResult
            {
                throw new RuntimeException('forced opening failure');
            }
        };

        $service = new FinancialAccountService(app(AuditService::class), $failingOpening);

        try {
            $service->createAccountResult($user, [
                'name' => 'Rollback Checking',
                'type' => 'checking',
                'currency_code' => 'PHP',
                'starting_balance' => '50.00',
                'idempotency_key' => (string) Str::uuid(),
            ]);
            $this->fail('Expected RuntimeException from opening failure');
        } catch (RuntimeException $e) {
            $this->assertSame('forced opening failure', $e->getMessage());
        }

        $this->assertSame($accountsBefore, FinancialAccount::query()->where('user_id', $user->id)->count());
        $this->assertSame($ledgersBefore, LedgerAccount::query()->where('user_id', $user->id)->count());
        $this->assertSame($journalsBefore, JournalEntry::query()->where('user_id', $user->id)->count());
        $this->assertBalancedAndIntegrity();
    }
}
