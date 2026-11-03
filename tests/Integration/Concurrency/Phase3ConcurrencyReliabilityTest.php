<?php

namespace Tests\Integration\Concurrency;

use App\DataTransferObjects\CreateFinancialTransactionInput;
use App\Enums\CategoryType;
use App\Enums\FinancialTransactionType;
use App\Exceptions\IdempotencyConflictException;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\JournalEntry;
use App\Models\JournalPosting;
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
            $driverCode = (int) ($payload['driver_code'] ?? 0);
            $error = (string) ($payload['error'] ?? '');
            $this->assertTrue(
                in_array($driverCode, [5, 6], true)
                || str_contains($error, 'database is locked')
                || str_contains($error, 'SQLITE_BUSY')
                || str_contains($error, 'SQLITE_LOCKED'),
                'Unexpected concurrent posting failure: '.json_encode($payload).' | output='.$result['output'],
            );
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

            $driverCode = (int) ($payload['driver_code'] ?? 0);
            $error = (string) ($payload['error'] ?? '');
            $this->assertTrue(
                in_array($driverCode, [5, 6], true)
                || str_contains($error, 'database is locked')
                || str_contains($error, 'SQLITE_BUSY')
                || str_contains($error, 'SQLITE_LOCKED'),
                'Unexpected idempotency worker failure: '.json_encode($payload),
            );
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
            $driverCode = (int) ($contender['driver_code'] ?? 0);
            $this->assertTrue(
                in_array($driverCode, [5, 6], true)
                || str_contains((string) ($contender['error'] ?? ''), 'database is locked')
                || str_contains((string) ($contender['error'] ?? ''), 'SQLITE_BUSY')
                || str_contains((string) ($contender['error'] ?? ''), 'SQLITE_LOCKED'),
                'Unexpected contender failure: '.json_encode($contender),
            );
            $this->assertLessThanOrEqual(7000, $waitMs);
            self::$scenarioNotes[] = "test_3: contender lock failure driver_code={$driverCode} wait_ms={$waitMs}";
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
        $this->assertNotEmpty(self::$scenarioNotes);

        // Observability for Phase 4 concurrency hardening comparisons (disposable DB only).
        fwrite(STDOUT, 'Phase3 scenario notes: '.implode(' | ', self::$scenarioNotes)."\n");
        fwrite(STDOUT, 'Phase3 observed lock wait samples (ms): '.json_encode(self::$observedLockWaits)."\n");
    }
}
