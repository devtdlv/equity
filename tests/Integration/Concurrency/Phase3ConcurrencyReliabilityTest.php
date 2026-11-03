<?php

namespace Tests\Integration\Concurrency;

use App\DataTransferObjects\CreateFinancialTransactionInput;
use App\Enums\CategoryType;
use App\Enums\FinancialTransactionType;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\LedgerIntegrityException;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\JournalEntry;
use App\Models\JournalPosting;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\AccountBalanceService;
use App\Services\CategoryService;
use App\Services\FinancialAccountService;
use App\Services\FinancialTransactionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Test;

/**
 * Phase 3 multi-process SQLite concurrency and reliability scenarios.
 */
final class Phase3ConcurrencyReliabilityTest extends Phase3IntegrationTestCase
{
    private const WORKER_COUNT = 4;

    private static ?int $userId = null;

    private static ?int $accountId = null;

    private static ?int $categoryId = null;

    private static int $openingMinor = 1_000_000; // ₱10,000.00

    private static array $observedLockWaits = [];

    private static array $scenarioNotes = [];

    /**
     * Phase 4B.3 lock-timing diagnostics (test infrastructure only).
     *
     * @var list<array<string, mixed>>
     */
    private static array $lockDiagnostics = [];

    private function coordinator(): Phase3WorkerCoordinator
    {
        $env = [];
        foreach ([
            'PATH', 'PATHEXT', 'SystemRoot', 'SystemDrive', 'COMSPEC', 'TEMP', 'TMP',
            'HOME', 'USERPROFILE', 'HOMEDRIVE', 'HOMEPATH', 'PHP_INI_SCAN_DIR',
            'APP_ENV', 'APP_KEY', 'APP_DEBUG', 'APP_URL',
            'DB_CONNECTION', 'DB_DATABASE', 'DB_URL', 'DATABASE_URL',
            'DB_SOCKET', 'DB_UNIX_SOCKET', 'DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD',
            'DB_DSN', 'MYSQL_PWD', 'DB_FOREIGN_KEYS', 'DB_BUSY_TIMEOUT', 'DB_JOURNAL_MODE',
            'APP_CONFIG_CACHE', 'CACHE_STORE', 'SESSION_DRIVER', 'QUEUE_CONNECTION', 'MAIL_MAILER',
            'EQUITY_PHASE3_SQLITE_PATH', 'EQUITY_PHASE3_TOKEN', 'EQUITY_PHASE3_REPO_ROOT',
            'EQUITY_PHASE3_FORBIDDEN_DB', 'EQUITY_PHASE3_WORKDIR',
        ] as $key) {
            $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
            if (is_string($value) && $value !== '') {
                $env[$key] = $value;
            } elseif ($value === '') {
                $env[$key] = '';
            }
        }

        foreach (['DB_URL', 'DATABASE_URL', 'DB_SOCKET', 'DB_UNIX_SOCKET', 'DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD', 'DB_DSN', 'MYSQL_PWD'] as $empty) {
            $env[$empty] = '';
        }

        $barrierPath = Phase3Isolation::workDir().DIRECTORY_SEPARATOR.'barrier-'.Phase3Isolation::runToken().'.sqlite';
        if (! is_file($barrierPath)) {
            $handle = fopen($barrierPath, 'x+b');
            $this->assertNotFalse($handle, 'exclusive create failed for Phase 3 barrier db');
            fclose($handle);
        }

        return new Phase3WorkerCoordinator(
            workDir: Phase3Isolation::workDir(),
            runToken: Phase3Isolation::runToken(),
            phpBinary: PHP_BINARY,
            workerScript: Phase3Isolation::repositoryRoot().DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'phase3-worker.php',
            childEnv: $env,
            cwd: Phase3Isolation::repositoryRoot(),
            barrier: new Phase3Barrier($barrierPath),
        );
    }

    #[Test]
    public function test_0_seed_fixture_for_concurrency_scenarios(): void
    {
        $this->assertSame('wal', strtolower((string) (DB::selectOne('PRAGMA journal_mode')->journal_mode ?? '')));
        $this->assertSame(1, (int) (DB::selectOne('PRAGMA foreign_keys')->foreign_keys ?? 0));
        $busy = DB::selectOne('PRAGMA busy_timeout');
        $busyTimeout = (int) ($busy->timeout ?? $busy->busy_timeout ?? -1);
        $this->assertSame(5000, $busyTimeout);

        $user = User::factory()->create([
            'email' => 'phase3-'.Str::lower(Str::random(8)).'@example.test',
        ]);
        self::$userId = $user->id;

        $account = app(FinancialAccountService::class)->createAccountResult($user, [
            'name' => 'Phase3 Checking',
            'type' => 'checking',
            'currency_code' => 'PHP',
            'starting_balance' => '10000.00',
            'idempotency_key' => (string) Str::uuid(),
        ])->account->load('ledgerAccount');

        self::$accountId = $account->id;
        self::$openingMinor = 1_000_000;

        $category = app(CategoryService::class)->createCategory($user, [
            'name' => 'Phase3 Groceries',
            'type' => CategoryType::Expense,
        ]);
        self::$categoryId = $category->id;

        $balance = app(AccountBalanceService::class)->getBalanceForLedgerAccount($account->ledgerAccount);
        $this->assertSame(self::$openingMinor, $balance->amountMinor);
    }

    #[Test]
    #[Depends('test_0_seed_fixture_for_concurrency_scenarios')]
    public function test_1_concurrent_transaction_posting(): void
    {
        $coord = $this->coordinator();
        $workers = [];
        for ($i = 0; $i < self::WORKER_COUNT; $i++) {
            $workers[] = [
                'id' => 'post_'.$i,
                'action' => 'post_expense',
                'payload' => [
                    'user_id' => self::$userId,
                    'financial_account_id' => self::$accountId,
                    'category_id' => self::$categoryId,
                    'amount' => '10.00',
                    'entry_date' => '2026-11-03',
                    'description' => 'Phase3 concurrent expense '.$i,
                    'idempotency_key' => (string) Str::uuid(),
                ],
            ];
        }

        // Release the parent connection so workers are not contending with the PHPUnit process.
        $this->disconnectParentSqlite();

        $results = $coord->runBarrierWave($workers, 90);
        $this->reconnectParentSqlite();
        $this->assertCount(self::WORKER_COUNT, $results);

        $createdIds = [];
        $successCount = 0;
        $lockFailures = 0;
        foreach ($results as $result) {
            $this->assertNotNull($result['result'], $result['output']);
            $payload = $result['result'];
            if (! empty($payload['ok'])) {
                $successCount++;
                $this->assertTrue($payload['was_recently_created'] ?? false);
                $this->assertSame($payload['debit_sum'], $payload['credit_sum']);
                $this->assertGreaterThanOrEqual(2, (int) $payload['posting_count']);
                $createdIds[] = (int) $payload['transaction_id'];

                continue;
            }

            // Expected SQLite writer serialization after application retries are exhausted.
            $this->assertSqliteLockFailure($payload, 'Unexpected concurrent posting failure');
            $lockFailures++;
            self::$observedLockWaits[] = (int) ($payload['elapsed_ms'] ?? 0);
        }

        $this->assertGreaterThan(0, $successCount, 'At least one concurrent posting must succeed.');
        $this->assertCount($successCount, array_unique($createdIds));

        // No partial financial rows from failed workers: every success id exists and balances.
        $this->assertSame($successCount, FinancialTransaction::query()->whereIn('id', $createdIds)->count());
        $journalIds = FinancialTransaction::query()->whereIn('id', $createdIds)->pluck('journal_entry_id');
        $journals = JournalEntry::query()->whereIn('id', $journalIds)->with('postings')->get();
        $this->assertCount($successCount, $journals);
        foreach ($journals as $journal) {
            $this->assertSame(
                (int) $journal->postings->sum('debit_amount_minor'),
                (int) $journal->postings->sum('credit_amount_minor'),
            );
        }

        $account = FinancialAccount::query()->with('ledgerAccount')->findOrFail(self::$accountId);
        $expected = self::$openingMinor - ($successCount * 1000);
        $actual = app(AccountBalanceService::class)->getBalanceForLedgerAccount($account->ledgerAccount)->amountMinor;
        $this->assertSame($expected, $actual);
        self::$openingMinor = $actual;
        self::$scenarioNotes[] = "test_1: {$successCount} successes, {$lockFailures} lock failures among ".self::WORKER_COUNT.' workers';
    }

    private function disconnectParentSqlite(): void
    {
        DB::purge('sqlite');
        DB::disconnect('sqlite');
    }

    private function reconnectParentSqlite(): void
    {
        config(['database.connections.sqlite.database' => Phase3Isolation::approvedDatabasePath()]);
        DB::reconnect('sqlite');
        $this->assertPhase3SqliteFileConnection();
    }

    #[Test]
    #[Depends('test_1_concurrent_transaction_posting')]
    public function test_2_concurrent_idempotency(): void
    {
        $coord = $this->coordinator();
        $sharedKey = (string) Str::uuid();
        $workers = [];
        for ($i = 0; $i < self::WORKER_COUNT; $i++) {
            $workers[] = [
                'id' => 'idem_'.$i,
                'action' => 'post_expense',
                'payload' => [
                    'user_id' => self::$userId,
                    'financial_account_id' => self::$accountId,
                    'category_id' => self::$categoryId,
                    'amount' => '25.00',
                    'entry_date' => '2026-11-03',
                    'description' => 'Phase3 idempotent expense',
                    'idempotency_key' => $sharedKey,
                ],
            ];
        }

        $this->disconnectParentSqlite();
        $results = $coord->runBarrierWave($workers, 90);
        $this->reconnectParentSqlite();

        $created = 0;
        $replays = 0;
        $lockFailures = 0;
        $txnIds = [];
        foreach ($results as $result) {
            $this->assertNotNull($result['result'], $result['output']);
            $payload = $result['result'];
            if (! empty($payload['ok'])) {
                if (! empty($payload['was_recently_created'])) {
                    $created++;
                } else {
                    $replays++;
                }
                $txnIds[] = (int) $payload['transaction_id'];

                continue;
            }

            $this->assertSqliteLockFailure($payload, 'Unexpected idempotency worker failure');
            $lockFailures++;
        }

        $this->assertSame(1, $created, 'Exactly one financial effect must be created for the shared key.');
        $this->assertCount(1, array_unique($txnIds));
        $this->assertSame(1, FinancialTransaction::query()->whereIn('id', $txnIds)->count());

        $beforeConflictBalance = app(AccountBalanceService::class)
            ->getBalanceForLedgerAccount(FinancialAccount::query()->with('ledgerAccount')->findOrFail(self::$accountId)->ledgerAccount)
            ->amountMinor;

        $conflictWorkers = [];
        for ($i = 0; $i < 2; $i++) {
            $conflictWorkers[] = [
                'id' => 'conflict_'.$i,
                'action' => 'post_expense',
                'payload' => [
                    'user_id' => self::$userId,
                    'financial_account_id' => self::$accountId,
                    'category_id' => self::$categoryId,
                    'amount' => '26.00',
                    'entry_date' => '2026-11-03',
                    'description' => 'Phase3 idempotent expense CONFLICT',
                    'idempotency_key' => $sharedKey,
                ],
            ];
        }

        $this->disconnectParentSqlite();
        $conflicts = $coord->runBarrierWave($conflictWorkers, 60);
        $this->reconnectParentSqlite();

        $conflictRejected = 0;
        foreach ($conflicts as $result) {
            $this->assertNotNull($result['result']);
            $payload = $result['result'];
            $this->assertFalse($payload['ok'] ?? true);
            if (($payload['error_class'] ?? null) === IdempotencyConflictException::class) {
                $conflictRejected++;

                continue;
            }
            $driverCode = (int) ($payload['driver_code'] ?? 0);
            $error = (string) ($payload['error'] ?? '');
            $this->assertTrue(
                in_array($driverCode, [5, 6], true)
                || str_contains($error, 'database is locked'),
                'Unexpected conflict-wave failure: '.json_encode($payload),
            );
        }
        $this->assertGreaterThan(0, $conflictRejected, 'At least one conflicting payload must be rejected as IdempotencyConflictException.');

        $afterConflictBalance = app(AccountBalanceService::class)
            ->getBalanceForLedgerAccount(FinancialAccount::query()->with('ledgerAccount')->findOrFail(self::$accountId)->ledgerAccount)
            ->amountMinor;
        $this->assertSame($beforeConflictBalance, $afterConflictBalance);
        self::$openingMinor = $afterConflictBalance;
        self::$scenarioNotes[] = "test_2: created={$created} replays={$replays} lock_failures={$lockFailures}; conflicts rejected without extra effects";
    }

    #[Test]
    #[Depends('test_2_concurrent_idempotency')]
    public function test_3_sqlite_write_contention(): void
    {
        $coord = $this->coordinator();
        $token = Phase3Isolation::runToken();
        $heldKey = 'contention_held_'.$token;
        $releaseKey = 'contention_release_'.$token;
        $enteringKey = 'contention_entering_'.$token;
        $barrier = new Phase3Barrier(
            Phase3Isolation::workDir().DIRECTORY_SEPARATOR.'barrier-'.$token.'.sqlite',
        );

        // Sequence: holder acquires write lock → signals held → parent starts contender →
        // contender signals it reached the write attempt → parent holds ≥1500ms → release.
        $holder = null;
        $contender = null;
        $holderResult = null;
        $contenderResult = null;

        $this->disconnectParentSqlite();
        try {
            $holder = $coord->spawnWorker('holder', 'hold_write_then_release', [
                'held_key' => $heldKey,
                'release_key' => $releaseKey,
            ]);
            $coord->waitForBarrierKey($heldKey, '1', 30);

            $contender = $coord->spawnWorker('contender', 'contended_write', [
                'entering_key' => $enteringKey,
            ]);
            $coord->waitForBarrierKey($enteringKey, '1', 30);
            // Brief settle so the contender's UPDATE is in-flight against the held lock
            // before we start the measured hold window (bounded; not a substitute for the signal).
            usleep(50_000);

            // Contender is at its write attempt while the holder still owns the lock.
            // Keep holding long enough to satisfy the minimum lock-wait assertion.
            usleep(1_800_000);

            $barrier->set($releaseKey, '1');

            $holderResult = $coord->awaitOwnedWorker($holder, 40);
            $holder = null;
            $contenderResult = $coord->awaitOwnedWorker($contender, 40);
            $contender = null;
        } catch (\Throwable $e) {
            try {
                $barrier->set($releaseKey, '1');
            } catch (\Throwable) {
            }
            if ($holder !== null) {
                $coord->cleanupOwnedWorker($holder);
                $holder = null;
            }
            if ($contender !== null) {
                $coord->cleanupOwnedWorker($contender);
                $contender = null;
            }
            throw $e;
        } finally {
            $this->reconnectParentSqlite();
        }

        $this->assertNotNull($holderResult);
        $this->assertNotNull($contenderResult);
        $this->assertTrue($holderResult['result']['ok'] ?? false, json_encode($holderResult));
        $contender = $contenderResult['result'];
        $this->assertNotNull($contender);

        $waitMs = (int) ($contender['wait_ms'] ?? 0);
        self::$observedLockWaits[] = $waitMs;

        if (! empty($contender['ok'])) {
            // Busy timeout allowed the contender to wait and succeed.
            $this->assertGreaterThanOrEqual(1500, $waitMs, 'Expected contender to wait while holder held the write lock.');
            $this->assertLessThanOrEqual(6000, $waitMs);
            self::$scenarioNotes[] = "test_3: contender succeeded after wait_ms={$waitMs}";
        } else {
            // Explicit lock failure is acceptable if no partial financial rows were written.
            $this->assertSqliteLockFailure($contender, 'Unexpected contender failure');
            $this->assertLessThanOrEqual(7000, $waitMs);
            self::$scenarioNotes[] = 'test_3: contender lock failure driver_code='
                .(int) ($contender['driver_code'] ?? 0).' wait_ms='.$waitMs;
        }

        // No financial mutation in this scenario beyond currency name no-op update.
        $this->assertSame(
            self::$openingMinor,
            app(AccountBalanceService::class)
                ->getBalanceForLedgerAccount(FinancialAccount::query()->with('ledgerAccount')->findOrFail(self::$accountId)->ledgerAccount)
                ->amountMinor,
        );
    }

    #[Test]
    #[Depends('test_3_sqlite_write_contention')]
    public function test_4_wal_reader_writer_isolation(): void
    {
        $coord = $this->coordinator();
        $heldKey = 'wal_held_'.Phase3Isolation::runToken();
        $releaseKey = 'wal_release_'.Phase3Isolation::runToken();

        $original = (string) DB::table('currencies')->where('code', 'PHP')->value('name');
        $committedName = 'PHP-WAL-'.substr(Phase3Isolation::runToken(), 0, 8);

        $writer = $coord->spawnWorker('wal_writer', 'hold_uncommitted_currency_rename', [
            'new_name' => $committedName,
            'held_key' => $heldKey,
            'release_key' => $releaseKey,
        ]);

        $coord->waitForBarrierKey($heldKey, '1', 40);

        $readerResults = $coord->runBarrierWave([
            [
                'id' => 'wal_reader_before',
                'action' => 'read_currency_name',
                'payload' => [],
            ],
        ], 60);
        $before = $readerResults[0]['result'];
        $this->assertTrue($before['ok'] ?? false);
        $this->assertSame($original, $before['name']);
        $this->assertSame('wal', $before['journal_mode']);
        $this->assertSame(1, (int) $before['foreign_keys']);

        // Parent → worker release via barrier (fresh PDO reads on the worker side).
        $barrier = new Phase3Barrier(Phase3Isolation::workDir().DIRECTORY_SEPARATOR.'barrier-'.Phase3Isolation::runToken().'.sqlite');
        $barrier->set($releaseKey, '1');

        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($writer['process']);
            if (is_array($status) && $status['running'] === false) {
                break;
            }
            usleep(20_000);
        }
        fclose($writer['pipes'][1]);
        fclose($writer['pipes'][2]);
        proc_close($writer['process']);

        $afterResults = $coord->runBarrierWave([
            [
                'id' => 'wal_reader_after',
                'action' => 'read_currency_name',
                'payload' => [],
            ],
        ], 60);
        $after = $afterResults[0]['result'];
        $this->assertTrue($after['ok'] ?? false);
        $this->assertSame($committedName, $after['name']);
        $this->assertSame('wal', $after['journal_mode']);
        $this->assertSame(1, (int) $after['foreign_keys']);

        DB::table('currencies')->where('code', 'PHP')->update(['name' => $original]);
        self::$scenarioNotes[] = 'test_4: WAL reader saw committed snapshot only after writer commit';
    }

    #[Test]
    #[Depends('test_4_wal_reader_writer_isolation')]
    public function test_5_transaction_atomicity_on_controlled_failure(): void
    {
        $user = User::query()->findOrFail(self::$userId);
        $account = FinancialAccount::query()->with('ledgerAccount')->findOrFail(self::$accountId);
        $service = app(FinancialTransactionService::class);

        $journalsBefore = JournalEntry::query()->count();
        $postingsBefore = JournalPosting::query()->count();
        $txnsBefore = FinancialTransaction::query()->count();
        $balanceBefore = app(AccountBalanceService::class)->getBalanceForLedgerAccount($account->ledgerAccount)->amountMinor;

        $shouldFail = true;
        JournalPosting::creating(function () use (&$shouldFail): void {
            if ($shouldFail) {
                $shouldFail = false;
                throw new \RuntimeException('Phase3 controlled mid-posting failure');
            }
        });

        try {
            $service->createTransaction(new CreateFinancialTransactionInput(
                user: $user,
                type: FinancialTransactionType::Expense,
                financialAccountId: $account->id,
                amount: '12.34',
                entryDate: '2026-11-03',
                description: 'Phase3 atomicity probe',
                idempotencyKey: (string) Str::uuid(),
                categoryId: self::$categoryId,
            ));
            $this->fail('Expected controlled mid-posting failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Phase3 controlled mid-posting failure', $e->getMessage());
        }

        $this->assertSame($journalsBefore, JournalEntry::query()->count());
        $this->assertSame($postingsBefore, JournalPosting::query()->count());
        $this->assertSame($txnsBefore, FinancialTransaction::query()->count());
        $this->assertSame(
            $balanceBefore,
            app(AccountBalanceService::class)->getBalanceForLedgerAccount($account->fresh()->ledgerAccount)->amountMinor,
        );
        self::$scenarioNotes[] = 'test_5: controlled failure rolled back completely';
    }

    #[Test]
    #[Depends('test_5_transaction_atomicity_on_controlled_failure')]
    public function test_6_final_balance_reconciliation_after_reconnect(): void
    {
        $path = Phase3Isolation::approvedDatabasePath();
        $accountId = self::$accountId;

        DB::purge('sqlite');
        DB::disconnect('sqlite');
        config(['database.connections.sqlite.database' => $path]);
        DB::reconnect('sqlite');
        $this->assertPhase3SqliteFileConnection();

        $account = FinancialAccount::query()->with('ledgerAccount')->findOrFail($accountId);
        $serviceBalance = app(AccountBalanceService::class)->getBalanceForLedgerAccount($account->ledgerAccount)->amountMinor;

        $row = DB::table('journal_postings as jp')
            ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
            ->where('jp.ledger_account_id', $account->ledgerAccount->id)
            ->where('je.status', 'posted')
            ->selectRaw('COALESCE(SUM(jp.debit_amount_minor),0) as d, COALESCE(SUM(jp.credit_amount_minor),0) as c')
            ->first();

        $independent = (int) $row->d - (int) $row->c; // asset account
        $this->assertSame($independent, $serviceBalance);
        $this->assertSame(self::$openingMinor, $serviceBalance);

        $unbalanced = DB::select(
            'select journal_entry_id, sum(debit_amount_minor) as d, sum(credit_amount_minor) as c
             from journal_postings
             group by journal_entry_id
             having d != c',
        );
        $this->assertSame([], $unbalanced);

        $duplicateKeys = DB::table('journal_entries')
            ->select('user_id', 'idempotency_key', DB::raw('COUNT(*) as c'))
            ->groupBy('user_id', 'idempotency_key')
            ->havingRaw('COUNT(*) > 1')
            ->get();
        $this->assertCount(0, $duplicateKeys);

        self::$scenarioNotes[] = "test_6: reconciled balance={$serviceBalance}; lock waits=".json_encode(self::$observedLockWaits);
    }

    #[Test]
    #[Depends('test_6_final_balance_reconciliation_after_reconnect')]
    public function test_7_interrupted_worker_recovery(): void
    {
        $coord = $this->coordinator();
        $heldKey = 'interrupt_held_'.Phase3Isolation::runToken();

        $originalName = (string) DB::table('currencies')->where('code', 'PHP')->value('name');

        $worker = $coord->spawnWorker('interrupt', 'open_txn_and_wait_forever', [
            'held_key' => $heldKey,
        ]);

        $coord->waitForBarrierKey($heldKey, '1', 40);

        $coord->terminateOwnedWorker($worker);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        proc_close($worker['process']);

        DB::purge('sqlite');
        DB::disconnect('sqlite');
        config(['database.connections.sqlite.database' => Phase3Isolation::approvedDatabasePath()]);
        DB::reconnect('sqlite');
        $this->assertPhase3SqliteFileConnection();

        $nameAfter = (string) DB::table('currencies')->where('code', 'PHP')->value('name');
        $this->assertSame($originalName, $nameAfter);
        $this->assertNotSame('PHP-INTERRUPTED', $nameAfter);

        $integrity = DB::selectOne('PRAGMA integrity_check');
        $integrityValue = is_object($integrity) ? (string) array_values((array) $integrity)[0] : '';
        $this->assertSame('ok', strtolower($integrityValue));

        // Database remains usable for a subsequent valid transaction.
        $user = User::query()->findOrFail(self::$userId);
        $created = app(FinancialTransactionService::class)->createTransaction(new CreateFinancialTransactionInput(
            user: $user,
            type: FinancialTransactionType::Expense,
            financialAccountId: self::$accountId,
            amount: '1.00',
            entryDate: '2026-11-03',
            description: 'Phase3 post-interrupt sanity',
            idempotencyKey: (string) Str::uuid(),
            categoryId: self::$categoryId,
        ));
        $this->assertTrue($created->wasRecentlyCreated);

        self::$openingMinor = app(AccountBalanceService::class)
            ->getBalanceForLedgerAccount(FinancialAccount::query()->with('ledgerAccount')->findOrFail(self::$accountId)->ledgerAccount)
            ->amountMinor;

        self::$scenarioNotes[] = 'test_7: interrupted uncommitted write absent; integrity_check=ok; subsequent txn ok';
    }

    #[Test]
    #[Depends('test_7_interrupted_worker_recovery')]
    public function test_8_concurrent_postings_different_accounts(): void
    {
        $coord = $this->coordinator();
        $user = User::query()->findOrFail(self::$userId);
        $accountService = app(FinancialAccountService::class);
        $balanceService = app(AccountBalanceService::class);

        $accountIds = [];
        $openingByAccount = [];
        for ($i = 0; $i < self::WORKER_COUNT; $i++) {
            $account = $accountService->createAccountResult($user, [
                'name' => 'Phase3 Multi '.$i,
                'type' => 'checking',
                'currency_code' => 'PHP',
                'starting_balance' => '1000.00',
                'idempotency_key' => (string) Str::uuid(),
            ])->account->load('ledgerAccount');
            $accountIds[] = (int) $account->id;
            $openingByAccount[(int) $account->id] = $balanceService
                ->getBalanceForLedgerAccount($account->ledgerAccount)
                ->amountMinor;
            $this->assertSame(100_000, $openingByAccount[(int) $account->id]);
        }

        $journalsBefore = JournalEntry::query()->count();
        $postingsBefore = JournalPosting::query()->count();
        $txnsBefore = FinancialTransaction::query()->count();

        $workers = [];
        for ($i = 0; $i < self::WORKER_COUNT; $i++) {
            $workers[] = [
                'id' => 'multi_acct_'.$i,
                'action' => 'post_expense',
                'payload' => [
                    'user_id' => self::$userId,
                    'financial_account_id' => $accountIds[$i],
                    'category_id' => self::$categoryId,
                    'amount' => '25.00',
                    'entry_date' => '2026-11-03',
                    'description' => 'Phase3 multi-account expense '.$i,
                    'idempotency_key' => (string) Str::uuid(),
                ],
            ];
        }

        $this->disconnectParentSqlite();
        $results = $coord->runBarrierWave($workers, 90);
        $this->reconnectParentSqlite();

        $successByAccount = array_fill_keys($accountIds, 0);
        $createdIds = [];
        $successCount = 0;
        $lockFailures = 0;
        foreach ($results as $result) {
            $this->assertNotNull($result['result'], $result['output']);
            $payload = $result['result'];
            if (! empty($payload['ok'])) {
                $successCount++;
                $accountId = (int) ($payload['financial_account_id'] ?? 0);
                $this->assertContains($accountId, $accountIds);
                $successByAccount[$accountId]++;
                $this->assertSame($payload['debit_sum'], $payload['credit_sum']);
                $this->assertGreaterThanOrEqual(2, (int) $payload['posting_count']);
                $createdIds[] = (int) $payload['transaction_id'];
                $this->recordFinancialOpLockDiagnostic('test_8', $payload);

                continue;
            }
            $this->assertSqliteLockFailure($payload, 'Unexpected multi-account posting failure');
            $lockFailures++;
            self::$observedLockWaits[] = (int) ($payload['elapsed_ms'] ?? 0);
            $this->recordFinancialOpLockDiagnostic('test_8', $payload);
        }

        $this->assertGreaterThan(0, $successCount);
        $this->assertSame($successCount, count(array_unique($createdIds)));
        $this->assertSame($successCount, FinancialTransaction::query()->whereIn('id', $createdIds)->count());

        foreach ($accountIds as $accountId) {
            $account = FinancialAccount::query()->with('ledgerAccount')->findOrFail($accountId);
            $expected = $openingByAccount[$accountId] - ($successByAccount[$accountId] * 2500);
            $actual = $balanceService->getBalanceForLedgerAccount($account->ledgerAccount)->amountMinor;
            $this->assertSame($expected, $actual, "Balance mismatch for account {$accountId}");
        }

        $this->assertBalancedJournalsForTransactions($createdIds);
        $this->assertSame($txnsBefore + $successCount, FinancialTransaction::query()->count());
        $this->assertSame($journalsBefore + $successCount, JournalEntry::query()->count());
        $this->assertGreaterThanOrEqual($postingsBefore + ($successCount * 2), JournalPosting::query()->count());

        self::$scenarioNotes[] = 'test_8: multi-account successes='.$successCount
            .' lock_failures='.$lockFailures
            .' among '.self::WORKER_COUNT.' workers'
            .' '.$this->summarizeScenarioDiagnostics('test_8');
    }

    #[Test]
    #[Depends('test_8_concurrent_postings_different_accounts')]
    public function test_9_concurrent_category_ledger_creation(): void
    {
        $coord = $this->coordinator();
        $user = User::query()->findOrFail(self::$userId);

        $category = app(CategoryService::class)->createCategory($user, [
            'name' => 'Phase3 Race Category '.Str::lower(Str::random(6)),
            'type' => CategoryType::Expense,
        ]);
        $categoryId = (int) $category->id;

        $this->assertSame(
            0,
            LedgerAccount::query()->where('category_id', $categoryId)->where('currency_code', 'PHP')->count(),
            'Category ledger must not exist before the concurrent wave.',
        );

        $journalsBefore = JournalEntry::query()->count();
        $postingsBefore = JournalPosting::query()->count();
        $txnsBefore = FinancialTransaction::query()->count();
        $ledgersBefore = LedgerAccount::query()->count();

        $workers = [];
        for ($i = 0; $i < self::WORKER_COUNT; $i++) {
            $workers[] = [
                'id' => 'cat_ledger_'.$i,
                'action' => 'post_expense',
                'payload' => [
                    'user_id' => self::$userId,
                    'financial_account_id' => self::$accountId,
                    'category_id' => $categoryId,
                    'amount' => '7.00',
                    'entry_date' => '2026-11-03',
                    'description' => 'Phase3 category-ledger race '.$i,
                    'idempotency_key' => (string) Str::uuid(),
                ],
            ];
        }

        $this->disconnectParentSqlite();
        $results = $coord->runBarrierWave($workers, 90);
        $this->reconnectParentSqlite();

        $successCount = 0;
        $lockFailures = 0;
        $createdIds = [];
        $categoryLedgerIds = [];
        foreach ($results as $result) {
            $this->assertNotNull($result['result'], $result['output']);
            $payload = $result['result'];
            if (! empty($payload['ok'])) {
                $successCount++;
                $createdIds[] = (int) $payload['transaction_id'];
                $this->assertSame($payload['debit_sum'], $payload['credit_sum']);
                $ledgerId = (int) ($payload['category_ledger_account_id'] ?? 0);
                $this->assertGreaterThan(0, $ledgerId);
                $categoryLedgerIds[] = $ledgerId;
                $this->recordFinancialOpLockDiagnostic('test_9', $payload);

                continue;
            }

            // Unique races must be recovered inside FinancialTransactionService retries.
            // A surfaced unique violation after exhaustion is an application failure, not
            // acceptable SQLite writer contention.
            if (! empty($payload['is_unique_violation'])
                || (int) ($payload['driver_code'] ?? 0) === 2067
                || ($payload['error_class'] ?? '') === \Illuminate\Database\UniqueConstraintViolationException::class
            ) {
                $this->fail(
                    'Unexpected unique-constraint failure after service retries (not acceptable contention): '
                    .json_encode($payload),
                );
            }

            $this->assertSqliteLockFailure($payload, 'Unexpected category-ledger race failure');
            $lockFailures++;
            self::$observedLockWaits[] = (int) ($payload['elapsed_ms'] ?? 0);
            $this->recordFinancialOpLockDiagnostic('test_9', $payload);
        }

        $this->assertGreaterThan(0, $successCount, 'At least one category-ledger race posting must succeed.');

        $categoryLedgers = LedgerAccount::query()
            ->where('category_id', $categoryId)
            ->where('currency_code', 'PHP')
            ->get();
        $this->assertCount(1, $categoryLedgers, 'Exactly one category ledger account must exist for the race identity.');
        $canonicalLedgerId = (int) $categoryLedgers->first()->id;
        $this->assertSame([$canonicalLedgerId], array_values(array_unique($categoryLedgerIds)));

        $this->assertSame($successCount, FinancialTransaction::query()->whereIn('id', $createdIds)->count());
        $this->assertBalancedJournalsForTransactions($createdIds);
        $this->assertSame($txnsBefore + $successCount, FinancialTransaction::query()->count());
        $this->assertSame($journalsBefore + $successCount, JournalEntry::query()->count());
        $this->assertSame($ledgersBefore + 1, LedgerAccount::query()->count());
        $this->assertGreaterThanOrEqual($postingsBefore + ($successCount * 2), JournalPosting::query()->count());

        $expectedOpening = self::$openingMinor - ($successCount * 700);
        $actual = app(AccountBalanceService::class)
            ->getBalanceForLedgerAccount(
                FinancialAccount::query()->with('ledgerAccount')->findOrFail(self::$accountId)->ledgerAccount,
            )
            ->amountMinor;
        $this->assertSame($expectedOpening, $actual);
        self::$openingMinor = $actual;

        self::$scenarioNotes[] = 'test_9: category-ledger successes='.$successCount
            .' lock_failures='.$lockFailures
            .'; ledger_id='.$canonicalLedgerId
            .' '.$this->summarizeScenarioDiagnostics('test_9');
    }

    #[Test]
    #[Depends('test_9_concurrent_category_ledger_creation')]
    public function test_10_concurrent_reversal_same_transaction(): void
    {
        $coord = $this->coordinator();
        $user = User::query()->findOrFail(self::$userId);
        $txnService = app(FinancialTransactionService::class);

        $original = $txnService->createTransaction(new CreateFinancialTransactionInput(
            user: $user,
            type: FinancialTransactionType::Expense,
            financialAccountId: self::$accountId,
            amount: '15.00',
            entryDate: '2026-11-03',
            description: 'Phase3 reverse-same original',
            idempotencyKey: (string) Str::uuid(),
            categoryId: self::$categoryId,
        ));
        $this->assertTrue($original->wasRecentlyCreated);
        $originalId = (int) $original->transaction->id;
        self::$openingMinor -= 1500;

        $balanceBeforeReverse = app(AccountBalanceService::class)
            ->getBalanceForLedgerAccount(
                FinancialAccount::query()->with('ledgerAccount')->findOrFail(self::$accountId)->ledgerAccount,
            )
            ->amountMinor;

        $journalsBefore = JournalEntry::query()->count();
        $txnsBefore = FinancialTransaction::query()->count();

        // Wave 1: distinct idempotency keys → exactly one reversal; others already-reversed or lock fail.
        $workers = [];
        for ($i = 0; $i < self::WORKER_COUNT; $i++) {
            $workers[] = [
                'id' => 'rev_same_'.$i,
                'action' => 'reverse_transaction',
                'payload' => [
                    'user_id' => self::$userId,
                    'original_transaction_id' => $originalId,
                    'idempotency_key' => (string) Str::uuid(),
                    'reason' => 'Phase3 concurrent same-txn reverse '.$i,
                ],
            ];
        }

        $this->disconnectParentSqlite();
        $results = $coord->runBarrierWave($workers, 90);
        $this->reconnectParentSqlite();

        $createdReversals = 0;
        $alreadyReversed = 0;
        $lockFailures = 0;
        $reversalTxnIds = [];
        foreach ($results as $result) {
            $this->assertNotNull($result['result'], $result['output']);
            $payload = $result['result'];
            if (! empty($payload['ok'])) {
                $this->assertTrue($payload['was_recently_created'] ?? false);
                $this->assertSame($originalId, (int) ($payload['original_transaction_id'] ?? 0));
                $this->assertSame($payload['debit_sum'], $payload['credit_sum']);
                $createdReversals++;
                $reversalTxnIds[] = (int) $payload['reversal_transaction_id'];
                $this->recordFinancialOpLockDiagnostic('test_10_race', $payload);

                continue;
            }
            if ($this->isExactAlreadyReversedFailure($payload)) {
                $alreadyReversed++;
                $this->recordFinancialOpLockDiagnostic('test_10_race', $payload, 'already_reversed');

                continue;
            }
            if (($payload['error_class'] ?? '') === LedgerIntegrityException::class) {
                $this->fail('Unexpected LedgerIntegrityException (not already-reversed): '.json_encode($payload));
            }
            $this->assertSqliteLockFailure($payload, 'Unexpected same-txn reverse failure');
            $lockFailures++;
            self::$observedLockWaits[] = (int) ($payload['elapsed_ms'] ?? 0);
            $this->recordFinancialOpLockDiagnostic('test_10_race', $payload);
        }

        $this->assertSame(1, $createdReversals, 'Exactly one reversal row must be created for the original transaction.');
        $this->assertSame(1, count(array_unique($reversalTxnIds)));
        $this->assertSame(
            1,
            FinancialTransaction::query()->where('reversed_transaction_id', $originalId)->count(),
        );
        $this->assertSame(
            $createdReversals + $alreadyReversed + $lockFailures,
            self::WORKER_COUNT,
        );

        $balanceAfter = app(AccountBalanceService::class)
            ->getBalanceForLedgerAccount(
                FinancialAccount::query()->with('ledgerAccount')->findOrFail(self::$accountId)->ledgerAccount,
            )
            ->amountMinor;
        $this->assertSame($balanceBeforeReverse + 1500, $balanceAfter);
        self::$openingMinor = $balanceAfter;

        $this->assertBalancedJournalsForTransactions($reversalTxnIds);
        $this->assertSame($txnsBefore + 1, FinancialTransaction::query()->count());
        $this->assertSame($journalsBefore + 1, JournalEntry::query()->count());

        // After the concurrent winner committed, a follow-up wave must see already-reversed
        // (SQLite may have surfaced busy during the race instead of this integrity error).
        $followWorkers = [];
        for ($i = 0; $i < 2; $i++) {
            $followWorkers[] = [
                'id' => 'rev_same_follow_'.$i,
                'action' => 'reverse_transaction',
                'payload' => [
                    'user_id' => self::$userId,
                    'original_transaction_id' => $originalId,
                    'idempotency_key' => (string) Str::uuid(),
                    'reason' => 'Phase3 post-commit already-reversed '.$i,
                ],
            ];
        }
        $this->disconnectParentSqlite();
        $followResults = $coord->runBarrierWave($followWorkers, 60);
        $this->reconnectParentSqlite();
        $followAlready = 0;
        $followLocks = 0;
        foreach ($followResults as $result) {
            $this->assertNotNull($result['result'], $result['output']);
            $payload = $result['result'];
            $this->assertTrue(empty($payload['ok']), 'Post-commit reverse must not create another reversal.');
            if ($this->isExactAlreadyReversedFailure($payload)) {
                $followAlready++;

                continue;
            }
            if (($payload['error_class'] ?? '') === LedgerIntegrityException::class) {
                $this->fail('Unexpected LedgerIntegrityException (not already-reversed): '.json_encode($payload));
            }
            $this->assertSqliteLockFailure($payload, 'Unexpected post-commit reverse failure');
            $followLocks++;
        }
        $this->assertSame(
            1,
            FinancialTransaction::query()->where('reversed_transaction_id', $originalId)->count(),
        );
        $this->assertGreaterThan(0, $followAlready + $followLocks);

        // Wave 2: shared idempotency key on a fresh original → one create, remaining replays or lock fails.
        $sharedKey = (string) Str::uuid();
        $secondOriginal = $txnService->createTransaction(new CreateFinancialTransactionInput(
            user: $user,
            type: FinancialTransactionType::Expense,
            financialAccountId: self::$accountId,
            amount: '5.00',
            entryDate: '2026-11-03',
            description: 'Phase3 reverse-idempotent original',
            idempotencyKey: (string) Str::uuid(),
            categoryId: self::$categoryId,
        ));
        $secondId = (int) $secondOriginal->transaction->id;
        self::$openingMinor -= 500;

        $balanceBeforeIdemReverse = app(AccountBalanceService::class)
            ->getBalanceForLedgerAccount(
                FinancialAccount::query()->with('ledgerAccount')->findOrFail(self::$accountId)->ledgerAccount,
            )
            ->amountMinor;
        $this->assertSame(self::$openingMinor, $balanceBeforeIdemReverse);

        $idemWorkers = [];
        for ($i = 0; $i < self::WORKER_COUNT; $i++) {
            $idemWorkers[] = [
                'id' => 'rev_idem_'.$i,
                'action' => 'reverse_transaction',
                'payload' => [
                    'user_id' => self::$userId,
                    'original_transaction_id' => $secondId,
                    'idempotency_key' => $sharedKey,
                    'reason' => 'Phase3 shared reverse idempotency',
                ],
            ];
        }

        $txnsBeforeIdem = FinancialTransaction::query()->count();
        $journalsBeforeIdem = JournalEntry::query()->count();
        $this->disconnectParentSqlite();
        $idemResults = $coord->runBarrierWave($idemWorkers, 90);
        $this->reconnectParentSqlite();

        $idemCreated = 0;
        $idemReplays = 0;
        $idemLockFailures = 0;
        $idemConflicts = 0;
        $idemAlreadyReversed = 0;
        $idemReversalIds = [];
        foreach ($idemResults as $result) {
            $this->assertNotNull($result['result'], $result['output']);
            $payload = $result['result'];
            if (! empty($payload['ok'])) {
                $idemReversalIds[] = (int) $payload['reversal_transaction_id'];
                $this->assertSame($payload['debit_sum'], $payload['credit_sum']);
                if (! empty($payload['was_recently_created'])) {
                    $idemCreated++;
                } else {
                    $idemReplays++;
                }

                continue;
            }
            if (($payload['error_class'] ?? '') === IdempotencyConflictException::class) {
                $idemConflicts++;

                continue;
            }
            if ($this->isExactAlreadyReversedFailure($payload)) {
                // Possible if one worker created the reversal and another raced past
                // idempotency lookup before seeing the committed row.
                $idemAlreadyReversed++;

                continue;
            }
            if (($payload['error_class'] ?? '') === LedgerIntegrityException::class) {
                $this->fail('Unexpected LedgerIntegrityException (not already-reversed): '.json_encode($payload));
            }
            $this->assertSqliteLockFailure($payload, 'Unexpected idempotent reverse failure');
            $idemLockFailures++;
        }

        $this->assertSame(1, $idemCreated);
        $this->assertSame(1, count(array_unique($idemReversalIds)));
        $this->assertSame(1, FinancialTransaction::query()->where('reversed_transaction_id', $secondId)->count());
        $this->assertSame($txnsBeforeIdem + 1, FinancialTransaction::query()->count());
        $this->assertSame($journalsBeforeIdem + 1, JournalEntry::query()->count());
        $this->assertBalancedJournalsForTransactions($idemReversalIds);

        // Shared-key reverse must restore exactly the ₱5.00 expense once — no duplicate effects.
        $balanceAfterIdemReverse = app(AccountBalanceService::class)
            ->getBalanceForLedgerAccount(
                FinancialAccount::query()->with('ledgerAccount')->findOrFail(self::$accountId)->ledgerAccount,
            )
            ->amountMinor;
        $this->assertSame(
            $balanceBeforeIdemReverse + 500,
            $balanceAfterIdemReverse,
            'Shared-idempotency reverse wave must restore exactly 500 minor units once.',
        );
        self::$openingMinor = $balanceAfterIdemReverse;

        self::$scenarioNotes[] = 'test_10: same-txn created='.$createdReversals
            .' race_already_reversed='.$alreadyReversed
            .' race_lock_failures='.$lockFailures
            .'; follow_already_reversed='.$followAlready
            .' follow_locks='.$followLocks
            .'; idempotent created='.$idemCreated
            .' replays='.$idemReplays
            .' conflicts='.$idemConflicts
            .' already_reversed='.$idemAlreadyReversed
            .' lock_failures='.$idemLockFailures
            .' '.$this->summarizeScenarioDiagnostics('test_10_race');
    }

    #[Test]
    #[Depends('test_10_concurrent_reversal_same_transaction')]
    public function test_11_concurrent_reversal_different_transactions(): void
    {
        $coord = $this->coordinator();
        $user = User::query()->findOrFail(self::$userId);
        $txnService = app(FinancialTransactionService::class);

        $originalIds = [];
        for ($i = 0; $i < self::WORKER_COUNT; $i++) {
            $created = $txnService->createTransaction(new CreateFinancialTransactionInput(
                user: $user,
                type: FinancialTransactionType::Expense,
                financialAccountId: self::$accountId,
                amount: '8.00',
                entryDate: '2026-11-03',
                description: 'Phase3 reverse-diff original '.$i,
                idempotencyKey: (string) Str::uuid(),
                categoryId: self::$categoryId,
            ));
            $this->assertTrue($created->wasRecentlyCreated);
            $originalIds[] = (int) $created->transaction->id;
            self::$openingMinor -= 800;
        }

        $balanceBefore = app(AccountBalanceService::class)
            ->getBalanceForLedgerAccount(
                FinancialAccount::query()->with('ledgerAccount')->findOrFail(self::$accountId)->ledgerAccount,
            )
            ->amountMinor;
        $this->assertSame(self::$openingMinor, $balanceBefore);

        $journalsBefore = JournalEntry::query()->count();
        $txnsBefore = FinancialTransaction::query()->count();

        $workers = [];
        for ($i = 0; $i < self::WORKER_COUNT; $i++) {
            $workers[] = [
                'id' => 'rev_diff_'.$i,
                'action' => 'reverse_transaction',
                'payload' => [
                    'user_id' => self::$userId,
                    'original_transaction_id' => $originalIds[$i],
                    'idempotency_key' => (string) Str::uuid(),
                    'reason' => 'Phase3 reverse different '.$i,
                ],
            ];
        }

        $this->disconnectParentSqlite();
        $results = $coord->runBarrierWave($workers, 90);
        $this->reconnectParentSqlite();

        $successCount = 0;
        $lockFailures = 0;
        $reversalIds = [];
        $reversedOriginals = [];
        foreach ($results as $result) {
            $this->assertNotNull($result['result'], $result['output']);
            $payload = $result['result'];
            if (! empty($payload['ok'])) {
                $successCount++;
                $this->assertTrue($payload['was_recently_created'] ?? false);
                $this->assertSame($payload['debit_sum'], $payload['credit_sum']);
                $reversalIds[] = (int) $payload['reversal_transaction_id'];
                $reversedOriginals[] = (int) $payload['original_transaction_id'];
                $this->recordFinancialOpLockDiagnostic('test_11', $payload);

                continue;
            }
            $this->assertSqliteLockFailure($payload, 'Unexpected different-txn reverse failure');
            $lockFailures++;
            self::$observedLockWaits[] = (int) ($payload['elapsed_ms'] ?? 0);
            $this->recordFinancialOpLockDiagnostic('test_11', $payload);
        }

        $this->assertGreaterThan(0, $successCount);
        $this->assertCount($successCount, array_unique($reversalIds));
        $this->assertCount($successCount, array_unique($reversedOriginals));

        foreach ($reversedOriginals as $originalId) {
            $this->assertSame(
                1,
                FinancialTransaction::query()->where('reversed_transaction_id', $originalId)->count(),
                "Original {$originalId} must be reversed at most once.",
            );
        }

        foreach (array_diff($originalIds, $reversedOriginals) as $unreversedId) {
            $this->assertSame(
                0,
                FinancialTransaction::query()->where('reversed_transaction_id', $unreversedId)->count(),
                "Lock-failed original {$unreversedId} must remain unreversed.",
            );
        }

        $this->assertBalancedJournalsForTransactions($reversalIds);
        $this->assertSame($txnsBefore + $successCount, FinancialTransaction::query()->count());
        $this->assertSame($journalsBefore + $successCount, JournalEntry::query()->count());

        $expectedBalance = $balanceBefore + ($successCount * 800);
        $actualBalance = app(AccountBalanceService::class)
            ->getBalanceForLedgerAccount(
                FinancialAccount::query()->with('ledgerAccount')->findOrFail(self::$accountId)->ledgerAccount,
            )
            ->amountMinor;
        $this->assertSame($expectedBalance, $actualBalance);
        self::$openingMinor = $actualBalance;

        self::$scenarioNotes[] = 'test_11: different-txn reversals successes='.$successCount
            .' lock_failures='.$lockFailures
            .' among '.self::WORKER_COUNT.' workers'
            .' '.$this->summarizeScenarioDiagnostics('test_11');
    }

    #[Test]
    #[Depends('test_11_concurrent_reversal_different_transactions')]
    public function test_12_controlled_sqlite_lock_diagnostics(): void
    {
        $coord = $this->coordinator();
        $token = Phase3Isolation::runToken();

        // --- Experiment A: deferred read snapshot, peer commits, then first write ---
        $readA = 'diag_snap_read_a_'.$token;
        $readB = 'diag_snap_read_b_'.$token;
        $committed = 'diag_snap_committed_'.$token;

        $this->disconnectParentSqlite();
        try {
            $writer = $coord->spawnWorker('diag_snap_writer', 'diag_snapshot_writer', [
                'read_key' => $readA,
                'peer_read_key' => $readB,
                'committed_key' => $committed,
                'name_suffix' => 'W',
            ]);
            $contender = $coord->spawnWorker('diag_snap_contender', 'diag_snapshot_contender', [
                'read_key' => $readB,
                'peer_read_key' => $readA,
                'committed_key' => $committed,
                'name_suffix' => 'C',
            ]);
            $writerResult = $coord->awaitOwnedWorker($writer, 40);
            $contenderResult = $coord->awaitOwnedWorker($contender, 40);
        } catch (\Throwable $e) {
            if (isset($writer)) {
                $coord->cleanupOwnedWorker($writer);
            }
            if (isset($contender)) {
                $coord->cleanupOwnedWorker($contender);
            }
            throw $e;
        } finally {
            $this->reconnectParentSqlite();
        }

        $this->assertTrue($writerResult['result']['ok'] ?? false, json_encode($writerResult));
        $snapPayload = $contenderResult['result'];
        $this->assertNotNull($snapPayload);
        $snapWriteMs = (int) ($snapPayload['diag']['write_attempt_ms'] ?? -1);
        $this->assertGreaterThanOrEqual(0, $snapWriteMs);
        $this->recordControlledDiagnostic('diag_snapshot_upgrade', $snapPayload);

        if (! empty($snapPayload['ok'])) {
            // Measured outcome: this environment allowed the upgrade (unexpected vs SQLite docs).
            // Record it; do not invent BUSY_SNAPSHOT. Restore currency name for cleanliness.
            DB::table('currencies')->where('code', 'PHP')->update(['name' => 'Philippine Peso']);
            self::$scenarioNotes[] = 'test_12: snapshot_contender UNEXPECTED_SUCCESS write_attempt_ms='.$snapWriteMs;
        } else {
            $this->assertSqliteLockFailure($snapPayload, 'Controlled snapshot contender');
            // Heuristic only: immediate if write attempt returns well under 1s (not a claim of BUSY_SNAPSHOT).
            $this->assertLessThan(
                1000,
                $snapWriteMs,
                'Snapshot-contender write_attempt_ms should not approach busy_timeout if upgrade fails without waiting.',
            );
            DB::table('currencies')->where('code', 'PHP')->update(['name' => 'Philippine Peso']);
        }

        // --- Experiment B: held writer; contender begins with a write (busy_timeout can wait) ---
        $heldKey = 'diag_held_'.$token;
        $releaseKey = 'diag_release_'.$token;
        $enteringKey = 'diag_entering_'.$token;
        $barrier = new Phase3Barrier(
            Phase3Isolation::workDir().DIRECTORY_SEPARATOR.'barrier-'.$token.'.sqlite',
        );

        $this->disconnectParentSqlite();
        $heldWriter = null;
        $heldContender = null;
        try {
            $heldWriter = $coord->spawnWorker('diag_held_writer', 'diag_held_writer', [
                'held_key' => $heldKey,
                'release_key' => $releaseKey,
            ]);
            $coord->waitForBarrierKey($heldKey, '1', 30);

            $heldContender = $coord->spawnWorker('diag_held_contender', 'diag_contend_held_write', [
                'held_key' => $heldKey,
                'entering_key' => $enteringKey,
            ]);
            $coord->waitForBarrierKey($enteringKey, '1', 30);
            // Keep the write lock held long enough that a waiting contender can accrue delay.
            usleep(1_500_000);
            $barrier->set($releaseKey, '1');

            $heldWriterResult = $coord->awaitOwnedWorker($heldWriter, 40);
            $heldWriter = null;
            $heldContenderResult = $coord->awaitOwnedWorker($heldContender, 40);
            $heldContender = null;
        } catch (\Throwable $e) {
            try {
                $barrier->set($releaseKey, '1');
            } catch (\Throwable) {
            }
            if ($heldWriter !== null) {
                $coord->cleanupOwnedWorker($heldWriter);
            }
            if ($heldContender !== null) {
                $coord->cleanupOwnedWorker($heldContender);
            }
            throw $e;
        } finally {
            $this->reconnectParentSqlite();
        }

        $this->assertTrue($heldWriterResult['result']['ok'] ?? false, json_encode($heldWriterResult));
        $heldPayload = $heldContenderResult['result'];
        $this->assertNotNull($heldPayload);
        $heldWriteMs = (int) ($heldPayload['diag']['write_attempt_ms'] ?? ($heldPayload['elapsed_ms'] ?? -1));
        $this->recordControlledDiagnostic('diag_held_writer_wait', $heldPayload);

        // Contender may succeed after wait or fail with lock codes; either is informative.
        if (! empty($heldPayload['ok'])) {
            $this->assertGreaterThanOrEqual(
                1000,
                $heldWriteMs,
                'Successful held-contender write should have waited while the peer held the lock.',
            );
        } else {
            $this->assertSqliteLockFailure($heldPayload, 'Controlled held-writer contender');
        }

        self::$scenarioNotes[] = 'test_12: snapshot_contender write_attempt_ms='.$snapWriteMs
            .' driver='.(int) ($snapPayload['driver_code'] ?? 0)
            .' extended='.json_encode($snapPayload['extended_driver_code'] ?? null)
            .'; held_contender ok='.(empty($heldPayload['ok']) ? '0' : '1')
            .' write_attempt_ms='.$heldWriteMs
            .' driver='.(int) ($heldPayload['driver_code'] ?? 0);
    }

    #[Test]
    #[Depends('test_12_controlled_sqlite_lock_diagnostics')]
    public function test_13_deferred_vs_immediate_comparison(): void
    {
        $coord = $this->coordinator();

        // Synthetic currency-name races only. Not production FinancialTransactionService.
        // IMMEDIATE uses explicit SQL in the worker PDO path — never Laravel beginTransaction().
        $deferred = $this->runTxnModeComparisonWave($coord, 'deferred', readBeforeWrite: false);
        DB::table('currencies')->where('code', 'PHP')->update(['name' => 'Philippine Peso']);

        $immediate = $this->runTxnModeComparisonWave($coord, 'immediate', readBeforeWrite: false);
        DB::table('currencies')->where('code', 'PHP')->update(['name' => 'Philippine Peso']);

        $deferredRead = $this->runTxnModeComparisonWave($coord, 'deferred', readBeforeWrite: true);
        DB::table('currencies')->where('code', 'PHP')->update(['name' => 'Philippine Peso']);

        $immediateRead = $this->runTxnModeComparisonWave($coord, 'immediate', readBeforeWrite: true);
        DB::table('currencies')->where('code', 'PHP')->update(['name' => 'Philippine Peso']);

        foreach ([$deferred, $immediate, $deferredRead, $immediateRead] as $wave) {
            $this->assertGreaterThan(0, $wave['successes'], 'At least one synthetic writer must commit per mode wave: '.json_encode($wave['summary']));
            $this->assertSame(
                self::WORKER_COUNT,
                $wave['successes'] + $wave['lock_failures'] + $wave['other_errors'],
            );
            $this->assertSame(0, $wave['other_errors'], 'Unexpected non-lock errors: '.json_encode($wave));
        }

        // Sustained held-writer contention: DEFERRED contender waits on first WRITE;
        // IMMEDIATE contender waits on BEGIN IMMEDIATE (entering signaled before BEGIN).
        $heldDeferred = $this->runHeldWriterModeContender($coord, 'deferred');
        DB::table('currencies')->where('code', 'PHP')->update(['name' => 'Philippine Peso']);
        $heldImmediate = $this->runHeldWriterModeContender($coord, 'immediate');
        DB::table('currencies')->where('code', 'PHP')->update(['name' => 'Philippine Peso']);

        $integrity = DB::selectOne('PRAGMA integrity_check');
        $integrityValue = is_object($integrity) ? (string) array_values((array) $integrity)[0] : '';
        $this->assertSame('ok', strtolower($integrityValue));
        $this->assertSame('Philippine Peso', (string) DB::table('currencies')->where('code', 'PHP')->value('name'));
        $this->assertSame(1, (int) DB::table('currencies')->where('code', 'PHP')->count());

        $comparison = [
            'worker_count' => self::WORKER_COUNT,
            'deferred_write_first' => $deferred['summary'],
            'immediate_write_first' => $immediate['summary'],
            'deferred_read_then_write' => $deferredRead['summary'],
            'immediate_read_then_write' => $immediateRead['summary'],
            'held_writer_deferred_contender' => $heldDeferred,
            'held_writer_immediate_contender' => $heldImmediate,
            'synthetic_workload' => true,
            'note' => 'IMMEDIATE measured via diagnostic PDO BEGIN IMMEDIATE only; not production services.',
        ];
        self::$lockDiagnostics[] = [
            'scenario' => 'test_13_comparison',
            'classification' => 'mode_comparison',
            'comparison' => $comparison,
        ];

        self::$scenarioNotes[] = 'test_13: '.$this->formatTxnModeWaveNote('deferred', $deferred)
            .' | '.$this->formatTxnModeWaveNote('immediate', $immediate)
            .' | '.$this->formatTxnModeWaveNote('deferred_read', $deferredRead)
            .' | '.$this->formatTxnModeWaveNote('immediate_read', $immediateRead)
            .' | held_deferred ok='.(empty($heldDeferred['ok']) ? '0' : '1')
            .' begin_ms='.json_encode($heldDeferred['begin_wait_ms'] ?? null)
            .' write_ms='.json_encode($heldDeferred['write_attempt_ms'] ?? null)
            .' | held_immediate ok='.(empty($heldImmediate['ok']) ? '0' : '1')
            .' begin_ms='.json_encode($heldImmediate['begin_wait_ms'] ?? null)
            .' write_ms='.json_encode($heldImmediate['write_attempt_ms'] ?? null);

        $this->assertNotEmpty(self::$scenarioNotes);
        fwrite(STDOUT, 'Phase3 scenario notes: '.implode(' | ', self::$scenarioNotes)."\n");
        fwrite(STDOUT, 'Phase3 observed lock wait samples (ms): '.json_encode(self::$observedLockWaits)."\n");
        fwrite(STDOUT, 'Phase3 lock diagnostics: '.json_encode(self::$lockDiagnostics)."\n");
        fwrite(STDOUT, 'Phase3 txn-mode comparison: '.json_encode($comparison)."\n");
    }

    /**
     * @return array{
     *   successes: int,
     *   lock_failures: int,
     *   other_errors: int,
     *   summary: array<string, mixed>,
     *   results: list<array<string, mixed>>
     * }
     */
    private function runTxnModeComparisonWave(
        Phase3WorkerCoordinator $coord,
        string $txnMode,
        bool $readBeforeWrite,
    ): array {
        $scenario = 'txn_mode_'.$txnMode.($readBeforeWrite ? '_read' : '_write');
        $workers = [];
        for ($i = 0; $i < self::WORKER_COUNT; $i++) {
            $workers[] = [
                'id' => $scenario.'_'.$i,
                'action' => 'diag_txn_mode_race',
                'payload' => [
                    'txn_mode' => $txnMode,
                    'worker_tag' => (string) $i,
                    'read_before_write' => $readBeforeWrite,
                ],
            ];
        }

        $this->disconnectParentSqlite();
        try {
            $rawResults = $coord->runBarrierWave($workers, 90);
        } finally {
            $this->reconnectParentSqlite();
        }

        $successes = 0;
        $lockFailures = 0;
        $otherErrors = 0;
        $beginWaits = [];
        $writeWaits = [];
        $elapsed = [];
        $markers = [];
        $normalized = [];

        foreach ($rawResults as $result) {
            $this->assertNotNull($result['result'], $result['output']);
            $payload = $result['result'];
            $normalized[] = $payload;
            $diag = is_array($payload['diag'] ?? null) ? $payload['diag'] : [];
            $beginWaits[] = $diag['begin_wait_ms'] ?? null;
            $writeWaits[] = $diag['write_attempt_ms'] ?? null;
            $elapsed[] = (int) ($payload['elapsed_ms'] ?? 0);

            if (! empty($payload['ok'])) {
                $successes++;
                $markers[] = (string) ($payload['marker'] ?? '');
                $this->recordControlledDiagnostic($scenario, $payload);

                continue;
            }

            if (
                in_array((int) ($payload['driver_code'] ?? 0), [5, 6], true)
                || ! empty($payload['is_sqlite_lock'])
            ) {
                $lockFailures++;
                $this->recordControlledDiagnostic($scenario, $payload);
                self::$observedLockWaits[] = (int) ($payload['elapsed_ms'] ?? 0);

                continue;
            }

            $otherErrors++;
            $this->recordControlledDiagnostic($scenario, $payload);
        }

        $finalName = (string) DB::table('currencies')->where('code', 'PHP')->value('name');
        $this->assertTrue(
            $successes === 0 || in_array($finalName, $markers, true) || str_starts_with($finalName, 'PHP-CMP-'),
            'Committed marker should reflect a successful synthetic writer when any succeeded.',
        );

        return [
            'successes' => $successes,
            'lock_failures' => $lockFailures,
            'other_errors' => $otherErrors,
            'summary' => [
                'txn_mode' => $txnMode,
                'read_before_write' => $readBeforeWrite,
                'successes' => $successes,
                'lock_failures' => $lockFailures,
                'other_errors' => $otherErrors,
                'begin_wait_ms' => $beginWaits,
                'write_attempt_ms' => $writeWaits,
                'elapsed_ms' => $elapsed,
                'final_currency_name' => $finalName,
            ],
            'results' => $normalized,
        ];
    }

    /**
     * @param  array{successes: int, lock_failures: int, summary: array<string, mixed>}  $wave
     */
    private function formatTxnModeWaveNote(string $label, array $wave): string
    {
        $begin = array_values(array_filter(
            $wave['summary']['begin_wait_ms'] ?? [],
            static fn ($v): bool => $v !== null,
        ));
        $write = array_values(array_filter(
            $wave['summary']['write_attempt_ms'] ?? [],
            static fn ($v): bool => $v !== null,
        ));

        return $label
            .' ok='.$wave['successes']
            .' lock='.$wave['lock_failures']
            .' begin_ms='.json_encode($begin)
            .' write_ms='.json_encode($write);
    }

    /**
     * @return array<string, mixed>
     */
    private function runHeldWriterModeContender(Phase3WorkerCoordinator $coord, string $txnMode): array
    {
        $token = Phase3Isolation::runToken();
        $heldKey = 'cmp_held_'.$txnMode.'_'.$token;
        $releaseKey = 'cmp_release_'.$txnMode.'_'.$token;
        $enteringKey = 'cmp_entering_'.$txnMode.'_'.$token;
        $barrier = new Phase3Barrier(
            Phase3Isolation::workDir().DIRECTORY_SEPARATOR.'barrier-'.$token.'.sqlite',
        );

        $contenderAction = $txnMode === 'immediate'
            ? 'diag_contend_held_immediate'
            : 'diag_contend_held_write';

        $heldWriter = null;
        $heldContender = null;
        $this->disconnectParentSqlite();
        try {
            $heldWriter = $coord->spawnWorker('cmp_held_writer_'.$txnMode, 'diag_held_writer', [
                'held_key' => $heldKey,
                'release_key' => $releaseKey,
            ]);
            $coord->waitForBarrierKey($heldKey, '1', 30);

            $heldContender = $coord->spawnWorker('cmp_held_contender_'.$txnMode, $contenderAction, [
                'held_key' => $heldKey,
                'entering_key' => $enteringKey,
            ]);
            $coord->waitForBarrierKey($enteringKey, '1', 30);
            usleep(1_500_000);
            $barrier->set($releaseKey, '1');

            $writerResult = $coord->awaitOwnedWorker($heldWriter, 40);
            $heldWriter = null;
            $contenderResult = $coord->awaitOwnedWorker($heldContender, 40);
            $heldContender = null;
        } catch (\Throwable $e) {
            try {
                $barrier->set($releaseKey, '1');
            } catch (\Throwable) {
            }
            if ($heldWriter !== null) {
                $coord->cleanupOwnedWorker($heldWriter);
            }
            if ($heldContender !== null) {
                $coord->cleanupOwnedWorker($heldContender);
            }
            throw $e;
        } finally {
            $this->reconnectParentSqlite();
        }

        $this->assertTrue($writerResult['result']['ok'] ?? false, json_encode($writerResult));
        $payload = $contenderResult['result'];
        $this->assertNotNull($payload);
        $this->recordControlledDiagnostic('held_contender_'.$txnMode, $payload);

        if (! empty($payload['ok'])) {
            $waitMs = (int) ($payload['diag']['begin_wait_ms'] ?? $payload['diag']['write_attempt_ms'] ?? -1);
            $this->assertGreaterThanOrEqual(1000, $waitMs, 'Held-contender ('.$txnMode.') should wait while peer holds the write lock.');
        } else {
            $this->assertSqliteLockFailure($payload, 'Held-contender '.$txnMode);
        }

        return [
            'ok' => ! empty($payload['ok']),
            'txn_mode' => $txnMode,
            'begin_wait_ms' => $payload['diag']['begin_wait_ms'] ?? null,
            'write_attempt_ms' => $payload['diag']['write_attempt_ms'] ?? null,
            'elapsed_ms' => (int) ($payload['elapsed_ms'] ?? 0),
            'driver_code' => $payload['driver_code'] ?? null,
            'extended_driver_code' => $payload['extended_driver_code'] ?? null,
            'failure_phase' => $payload['diag']['failure_phase'] ?? null,
        ];
    }

    /**
     * Exact production already-reversed condition only — not any LedgerIntegrityException.
     *
     * @param  array<string, mixed>  $payload
     */
    private function isExactAlreadyReversedFailure(array $payload): bool
    {
        return ($payload['error_class'] ?? null) === LedgerIntegrityException::class
            && ($payload['already_reversed'] ?? false) === true
            && (string) ($payload['error'] ?? '') === 'This financial transaction has already been reversed.';
    }

    /**
     * Classify financial-op worker outcomes for diagnostics.
     *
     * Limitation: elapsed_ms includes up to three service retries + backoff, so
     * "immediate" / "delayed" here are heuristics on total wall time, not proof of
     * SQLITE_BUSY_SNAPSHOT vs busy_timeout.
     *
     * @param  array<string, mixed>  $payload
     */
    private function recordFinancialOpLockDiagnostic(string $scenario, array $payload, ?string $forcedClass = null): void
    {
        $elapsed = (int) ($payload['elapsed_ms'] ?? 0);
        $class = $forcedClass;
        if ($class === null) {
            if (! empty($payload['ok'])) {
                $class = 'successful_operation';
            } elseif (! empty($payload['is_unique_violation'])) {
                $class = 'unexpected_unique_violation';
            } elseif (
                in_array((int) ($payload['driver_code'] ?? 0), [5, 6], true)
                || ! empty($payload['is_sqlite_lock'])
                || ! empty($payload['laravel_deadlock_wrapper'])
                || ($payload['error_class'] ?? '') === \Illuminate\Database\DeadlockException::class
            ) {
                if ($elapsed < 100) {
                    $class = 'immediate_lock_failure';
                } elseif ($elapsed >= 1000) {
                    $class = 'delayed_lock_failure';
                } else {
                    $class = 'unknown';
                }
            } else {
                $class = 'unexpected_database_exception';
            }
        }

        self::$lockDiagnostics[] = [
            'scenario' => $scenario,
            'classification' => $class,
            'elapsed_ms' => $elapsed,
            'driver_code' => $payload['driver_code'] ?? null,
            'extended_driver_code' => $payload['extended_driver_code'] ?? null,
            'error_class' => $payload['error_class'] ?? null,
            'laravel_deadlock_wrapper' => $payload['laravel_deadlock_wrapper'] ?? false,
            'final_ok' => ! empty($payload['ok']),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordControlledDiagnostic(string $scenario, array $payload): void
    {
        $writeMs = (int) ($payload['diag']['write_attempt_ms'] ?? -1);
        $class = 'unknown';
        if (! empty($payload['ok'])) {
            $class = $writeMs >= 1000 ? 'delayed_lock_wait_then_success' : 'successful_operation';
        } elseif (
            in_array((int) ($payload['driver_code'] ?? 0), [5, 6], true)
            || ! empty($payload['is_sqlite_lock'])
        ) {
            $class = $writeMs >= 0 && $writeMs < 100
                ? 'immediate_lock_failure'
                : ($writeMs >= 1000 ? 'delayed_lock_failure' : 'unknown');
        } else {
            $class = 'unexpected_database_exception';
        }

        self::$lockDiagnostics[] = [
            'scenario' => $scenario,
            'classification' => $class,
            'write_attempt_ms' => $writeMs,
            'elapsed_ms' => (int) ($payload['elapsed_ms'] ?? 0),
            'driver_code' => $payload['driver_code'] ?? null,
            'extended_driver_code' => $payload['extended_driver_code'] ?? null,
            'failure_phase' => $payload['diag']['failure_phase'] ?? null,
            'first_write_after_reads' => $payload['diag']['first_write_after_reads'] ?? null,
            'final_ok' => ! empty($payload['ok']),
            // Do not label as BUSY_SNAPSHOT unless extended code 517 is actually present.
            'busy_snapshot_confirmed' => ((int) ($payload['extended_driver_code'] ?? 0) === 517),
        ];
    }

    private function summarizeScenarioDiagnostics(string $scenario): string
    {
        $counts = [];
        $elapsed = [];
        foreach (self::$lockDiagnostics as $row) {
            if (($row['scenario'] ?? '') !== $scenario) {
                continue;
            }
            $c = (string) ($row['classification'] ?? 'unknown');
            $counts[$c] = ($counts[$c] ?? 0) + 1;
            if (isset($row['elapsed_ms'])) {
                $elapsed[] = (int) $row['elapsed_ms'];
            }
        }

        return 'diag='.json_encode([
            'counts' => $counts,
            'elapsed_ms' => $elapsed,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Prefer structured SQLite driver codes (5 = BUSY, 6 = LOCKED).
     *
     * Message fallback remains only for rare PDO builds that omit the extended
     * result code in errorInfo[1] while still embedding SQLITE_BUSY/LOCKED text.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertSqliteLockFailure(array $payload, string $context): void
    {
        if (! empty($payload['is_unique_violation'])
            || (int) ($payload['driver_code'] ?? 0) === 2067
        ) {
            $this->fail($context.': unique-constraint failure is not SQLite lock contention: '.json_encode($payload));
        }

        $driverCode = (int) ($payload['driver_code'] ?? 0);
        if (
            in_array($driverCode, [5, 6], true)
            || ! empty($payload['is_sqlite_lock'])
            || ! empty($payload['laravel_deadlock_wrapper'])
            || ($payload['error_class'] ?? '') === \Illuminate\Database\DeadlockException::class
        ) {
            return;
        }

        $error = (string) ($payload['error'] ?? '');
        $this->assertTrue(
            str_contains($error, 'database is locked')
            || str_contains($error, 'SQLITE_BUSY')
            || str_contains($error, 'SQLITE_LOCKED'),
            $context.': expected SQLITE_BUSY(5)/SQLITE_LOCKED(6) driver_code, got: '.json_encode($payload),
        );
    }

    /**
     * @param  list<int>  $transactionIds
     */
    private function assertBalancedJournalsForTransactions(array $transactionIds): void
    {
        if ($transactionIds === []) {
            return;
        }

        $journalIds = FinancialTransaction::query()->whereIn('id', $transactionIds)->pluck('journal_entry_id');
        $journals = JournalEntry::query()->whereIn('id', $journalIds)->with('postings')->get();
        $this->assertCount(count(array_unique($transactionIds)), $journals);
        foreach ($journals as $journal) {
            $this->assertSame(
                (int) $journal->postings->sum('debit_amount_minor'),
                (int) $journal->postings->sum('credit_amount_minor'),
            );
            $this->assertGreaterThanOrEqual(2, $journal->postings->count());
        }
    }
}
