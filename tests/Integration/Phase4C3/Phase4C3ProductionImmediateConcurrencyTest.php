<?php

namespace Tests\Integration\Phase4C3;

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
use App\Services\OpeningEquityAccountService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Concurrency\Phase3Barrier;
use Tests\Integration\Concurrency\Phase3WorkerCoordinator;

/**
 * Phase 4C.3 — multi-process verification of production FinancialDatabaseTransaction IMMEDIATE.
 *
 * Workers use equity.sqlite.financial_immediate via EQUITY_FINANCIAL_IMMEDIATE only.
 * Does not register Phase 3 test-only IMMEDIATE resolvers. Parent keeps flag OFF.
 */
final class Phase4C3ProductionImmediateConcurrencyTest extends Phase4C3IntegrationTestCase
{
    private const WORKER_COUNT = 4;

    private static ?int $userId = null;

    private static ?int $accountId = null;

    private static ?int $categoryId = null;

    private static int $openingMinor = 1_000_000;

    /** @var list<string> */
    private static array $scenarioNotes = [];

    /** @var array<string, array{deferred: array<string, mixed>, immediate: array<string, mixed>}> */
    private static array $modeComparisons = [];

    /**
     * @param  bool|null  $financialImmediate  null omits env (parent default false); true/false forces worker flag.
     */
    private function coordinator(?bool $financialImmediate = null): Phase3WorkerCoordinator
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
            Phase4C3Isolation::ENV_SQLITE_PATH,
            Phase4C3Isolation::ENV_TOKEN,
            Phase4C3Isolation::ENV_REPO_ROOT,
            Phase4C3Isolation::ENV_FORBIDDEN_DB,
            Phase4C3Isolation::ENV_WORKDIR,
            Phase4C3Isolation::ENV_ENV_FILE,
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

        // Never inherit IMMEDIATE from the parent PHPUnit process.
        unset($env['EQUITY_FINANCIAL_IMMEDIATE']);
        if ($financialImmediate !== null) {
            $env['EQUITY_FINANCIAL_IMMEDIATE'] = $financialImmediate ? '1' : '0';
        }

        $barrierPath = Phase4C3Isolation::workDir().DIRECTORY_SEPARATOR.'barrier-'.Phase4C3Isolation::runToken().'.sqlite';
        if (! is_file($barrierPath)) {
            $handle = fopen($barrierPath, 'x+b');
            $this->assertNotFalse($handle, 'exclusive create failed for Phase 4C.3 barrier db');
            fclose($handle);
        }

        return new Phase3WorkerCoordinator(
            workDir: Phase4C3Isolation::workDir(),
            runToken: Phase4C3Isolation::runToken(),
            phpBinary: PHP_BINARY,
            workerScript: Phase4C3Isolation::repositoryRoot().DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'phase4c3-worker.php',
            childEnv: $env,
            cwd: Phase4C3Isolation::repositoryRoot(),
            barrier: new Phase3Barrier($barrierPath),
        );
    }

    private function disconnectParentSqlite(): void
    {
        DB::purge('sqlite');
        DB::disconnect('sqlite');
    }

    private function reconnectParentSqlite(): void
    {
        config([
            'database.connections.sqlite.database' => Phase4C3Isolation::approvedDatabasePath(),
            'equity.sqlite.financial_immediate' => false,
        ]);
        DB::reconnect('sqlite');
        $this->assertPhase4C3SqliteFileConnection();
        $this->assertFalse((bool) config('equity.sqlite.financial_immediate'));
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyMetrics(string $mode): array
    {
        return [
            'mode' => $mode,
            'financial_immediate' => $mode === 'immediate',
            'worker_count' => self::WORKER_COUNT,
            'successes' => 0,
            'idempotent_replays' => 0,
            'expected_domain_conflicts' => 0,
            'lock_failures' => 0,
            'unexpected_failures' => 0,
            'worker_elapsed_ms' => [],
            'integrity_ok' => false,
            'notes' => '',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isSqliteLockFailurePayload(array $payload): bool
    {
        if (! empty($payload['is_sqlite_lock']) || ! empty($payload['laravel_deadlock_wrapper'])) {
            return true;
        }

        $driverCode = (int) ($payload['driver_code'] ?? 0);
        if (in_array($driverCode, [5, 6], true)) {
            return true;
        }

        $error = strtolower((string) ($payload['error'] ?? ''));

        return str_contains($error, 'database is locked')
            || str_contains($error, 'sqlite_busy')
            || str_contains($error, 'sqlite_locked');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertSqliteLockFailure(array $payload, string $context): void
    {
        if (! empty($payload['is_unique_violation']) || (int) ($payload['driver_code'] ?? 0) === 2067) {
            $this->fail($context.': unique-constraint is not a SQLite lock failure: '.json_encode($payload));
        }

        $this->assertTrue(
            $this->isSqliteLockFailurePayload($payload),
            $context.': expected SQLITE_BUSY/LOCKED, got: '.json_encode($payload),
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

        $unique = array_values(array_unique($transactionIds));
        $journalIds = FinancialTransaction::query()->whereIn('id', $unique)->pluck('journal_entry_id');
        $journals = JournalEntry::query()->whereIn('id', $journalIds)->with('postings')->get();
        $this->assertCount(count($unique), $journals);
        foreach ($journals as $journal) {
            $this->assertSame(
                (int) $journal->postings->sum('debit_amount_minor'),
                (int) $journal->postings->sum('credit_amount_minor'),
            );
            $this->assertGreaterThanOrEqual(2, $journal->postings->count());
        }
    }

    private function assertGlobalLedgerIntegrity(): void
    {
        $integrity = DB::selectOne('PRAGMA integrity_check');
        $integrityValue = is_object($integrity) ? (string) array_values((array) $integrity)[0] : '';
        $this->assertSame('ok', strtolower($integrityValue));

        $unbalanced = DB::select(
            'SELECT journal_entry_id,
                    SUM(debit_amount_minor) AS debit_sum,
                    SUM(credit_amount_minor) AS credit_sum
             FROM journal_postings
             GROUP BY journal_entry_id
             HAVING debit_sum != credit_sum',
        );
        $this->assertSame([], $unbalanced, 'Every journal must be balanced.');

        $this->assertSame(0, (int) DB::transactionLevel());
        $this->assertFalse((bool) config('equity.sqlite.financial_immediate'));
    }

    #[Test]
    public function test_0_seed_fixture_and_parent_flag_off(): void
    {
        $this->assertFalse((bool) config('equity.sqlite.financial_immediate'));
        $this->assertSame('wal', strtolower((string) (DB::selectOne('PRAGMA journal_mode')->journal_mode ?? '')));
        $this->assertSame(1, (int) (DB::selectOne('PRAGMA foreign_keys')->foreign_keys ?? 0));
        $busy = DB::selectOne('PRAGMA busy_timeout');
        $this->assertSame(5000, (int) ($busy->timeout ?? $busy->busy_timeout ?? -1));

        $user = User::factory()->create([
            'email' => 'phase4c3-'.Str::lower(Str::random(8)).'@example.test',
        ]);
        self::$userId = $user->id;

        $account = app(FinancialAccountService::class)->createAccountResult($user, [
            'name' => 'Phase4C3 Checking',
            'type' => 'checking',
            'currency_code' => 'PHP',
            'starting_balance' => '10000.00',
            'idempotency_key' => (string) Str::uuid(),
        ])->account->load('ledgerAccount');

        self::$accountId = $account->id;
        self::$openingMinor = 1_000_000;

        $category = app(CategoryService::class)->createCategory($user, [
            'name' => 'Phase4C3 Groceries',
            'type' => CategoryType::Expense,
        ]);
        self::$categoryId = $category->id;

        $balance = app(AccountBalanceService::class)->getBalanceForLedgerAccount($account->ledgerAccount);
        $this->assertSame(self::$openingMinor, $balance->amountMinor);
        $this->assertGlobalLedgerIntegrity();
    }

    #[Test]
    #[Depends('test_0_seed_fixture_and_parent_flag_off')]
    public function test_1_concurrent_financial_transaction_creation(): void
    {
        $comparison = [
            'deferred' => $this->runConcurrentExpenseWave(false, 's1'),
            'immediate' => $this->runConcurrentExpenseWave(true, 's1'),
        ];
        self::$modeComparisons['scenario_1_concurrent_create'] = $comparison;
        self::$scenarioNotes[] = 's1: '.$this->formatPair($comparison);
        $this->assertGlobalLedgerIntegrity();
    }

    /**
     * @return array<string, mixed>
     */
    private function runConcurrentExpenseWave(bool $immediate, string $tag): array
    {
        $mode = $immediate ? 'immediate' : 'deferred';
        $metrics = $this->emptyMetrics($mode);
        $coord = $this->coordinator($immediate);

        $user = User::query()->findOrFail(self::$userId);
        $account = app(FinancialAccountService::class)->createAccountResult($user, [
            'name' => "P4C3 {$tag} {$mode} ".Str::lower(Str::random(4)),
            'type' => 'checking',
            'currency_code' => 'PHP',
            'starting_balance' => '5000.00',
            'idempotency_key' => (string) Str::uuid(),
        ])->account->load('ledgerAccount');
        $accountId = (int) $account->id;
        $opening = app(AccountBalanceService::class)->getBalanceForLedgerAccount($account->ledgerAccount)->amountMinor;

        $txnsBefore = FinancialTransaction::query()->count();
        $journalsBefore = JournalEntry::query()->count();

        $workers = [];
        for ($i = 0; $i < self::WORKER_COUNT; $i++) {
            $workers[] = [
                'id' => "{$tag}_post_{$mode}_{$i}",
                'action' => 'post_expense',
                'payload' => [
                    'user_id' => self::$userId,
                    'financial_account_id' => $accountId,
                    'category_id' => self::$categoryId,
                    'amount' => '10.00',
                    'entry_date' => '2026-11-03',
                    'description' => "P4C3 concurrent expense {$mode} {$i}",
                    'idempotency_key' => (string) Str::uuid(),
                ],
            ];
        }

        $this->disconnectParentSqlite();
        $results = $coord->runBarrierWave($workers, 90);
        $this->reconnectParentSqlite();

        $createdIds = [];
        foreach ($results as $result) {
            $this->assertNotNull($result['result'], $result['output']);
            $payload = $result['result'];
            $metrics['worker_elapsed_ms'][] = (int) ($payload['elapsed_ms'] ?? 0);
            $this->assertSame($immediate, (bool) ($payload['financial_immediate'] ?? false));

            if (! empty($payload['ok'])) {
                $metrics['successes']++;
                $this->assertTrue($payload['was_recently_created'] ?? false);
                $this->assertSame($payload['debit_sum'], $payload['credit_sum']);
                $this->assertSame(0, (int) ($payload['txn_level_after'] ?? -1));
                $createdIds[] = (int) $payload['transaction_id'];

                continue;
            }

            if ($this->isSqliteLockFailurePayload($payload)) {
                $this->assertSqliteLockFailure($payload, "s1 {$mode}");
                $this->assertSame(0, (int) ($payload['txn_level_after'] ?? -1));
                $metrics['lock_failures']++;

                continue;
            }

            $metrics['unexpected_failures']++;
            $this->fail("Unexpected concurrent create failure ({$mode}): ".json_encode($payload));
        }

        $this->assertGreaterThan(0, $metrics['successes']);
        $this->assertCount($metrics['successes'], array_unique($createdIds));
        $this->assertSame($metrics['successes'], FinancialTransaction::query()->whereIn('id', $createdIds)->count());
        $this->assertBalancedJournalsForTransactions($createdIds);

        $expected = $opening - ($metrics['successes'] * 1000);
        $actual = app(AccountBalanceService::class)
            ->getBalanceForLedgerAccount(FinancialAccount::query()->with('ledgerAccount')->findOrFail($accountId)->ledgerAccount)
            ->amountMinor;
        $this->assertSame($expected, $actual);
        $this->assertSame($txnsBefore + $metrics['successes'], FinancialTransaction::query()->count());
        $this->assertSame($journalsBefore + $metrics['successes'], JournalEntry::query()->count());

        $metrics['integrity_ok'] = true;
        $metrics['notes'] = "account={$accountId}";

        return $metrics;
    }

    #[Test]
    #[Depends('test_1_concurrent_financial_transaction_creation')]
    public function test_2_concurrent_same_idempotency_key(): void
    {
        $comparison = [
            'deferred' => $this->runSharedIdempotencyWave(false),
            'immediate' => $this->runSharedIdempotencyWave(true),
        ];
        self::$modeComparisons['scenario_2_idempotency'] = $comparison;
        self::$scenarioNotes[] = 's2: '.$this->formatPair($comparison);
        $this->assertGlobalLedgerIntegrity();
    }

    /**
     * @return array<string, mixed>
     */
    private function runSharedIdempotencyWave(bool $immediate): array
    {
        $mode = $immediate ? 'immediate' : 'deferred';
        $metrics = $this->emptyMetrics($mode);
        $coord = $this->coordinator($immediate);
        $sharedKey = (string) Str::uuid();

        $workers = [];
        for ($i = 0; $i < self::WORKER_COUNT; $i++) {
            $workers[] = [
                'id' => "idem_{$mode}_{$i}",
                'action' => 'post_expense',
                'payload' => [
                    'user_id' => self::$userId,
                    'financial_account_id' => self::$accountId,
                    'category_id' => self::$categoryId,
                    'amount' => '25.00',
                    'entry_date' => '2026-11-03',
                    'description' => 'P4C3 shared idempotency '.$mode,
                    'idempotency_key' => $sharedKey,
                ],
            ];
        }

        $balanceBefore = app(AccountBalanceService::class)
            ->getBalanceForLedgerAccount(FinancialAccount::query()->with('ledgerAccount')->findOrFail(self::$accountId)->ledgerAccount)
            ->amountMinor;

        $this->disconnectParentSqlite();
        $results = $coord->runBarrierWave($workers, 90);
        $this->reconnectParentSqlite();

        $txnIds = [];
        $created = 0;
        foreach ($results as $result) {
            $this->assertNotNull($result['result'], $result['output']);
            $payload = $result['result'];
            $metrics['worker_elapsed_ms'][] = (int) ($payload['elapsed_ms'] ?? 0);
            $this->assertSame($immediate, (bool) ($payload['financial_immediate'] ?? false));

            if (! empty($payload['ok'])) {
                if (! empty($payload['was_recently_created'])) {
                    $created++;
                    $metrics['successes']++;
                } else {
                    $metrics['idempotent_replays']++;
                }
                $txnIds[] = (int) $payload['transaction_id'];
                $this->assertSame(0, (int) ($payload['txn_level_after'] ?? -1));

                continue;
            }

            if ($this->isSqliteLockFailurePayload($payload)) {
                $this->assertSqliteLockFailure($payload, "s2 {$mode}");
                $metrics['lock_failures']++;

                continue;
            }

            $metrics['unexpected_failures']++;
            $this->fail("Unexpected idempotency failure ({$mode}): ".json_encode($payload));
        }

        $this->assertSame(1, $created);
        $this->assertCount(1, array_unique($txnIds));
        $this->assertSame(1, FinancialTransaction::query()->whereIn('id', $txnIds)->count());
        $this->assertBalancedJournalsForTransactions($txnIds);

        $balanceAfter = app(AccountBalanceService::class)
            ->getBalanceForLedgerAccount(FinancialAccount::query()->with('ledgerAccount')->findOrFail(self::$accountId)->ledgerAccount)
            ->amountMinor;
        $this->assertSame($balanceBefore - 2500, $balanceAfter);
        self::$openingMinor = $balanceAfter;

        // Conflicting payload with same key must remain a domain conflict.
        $conflictWorkers = [[
            'id' => "idem_conflict_{$mode}",
            'action' => 'post_expense',
            'payload' => [
                'user_id' => self::$userId,
                'financial_account_id' => self::$accountId,
                'category_id' => self::$categoryId,
                'amount' => '26.00',
                'entry_date' => '2026-11-03',
                'description' => 'P4C3 CONFLICT '.$mode,
                'idempotency_key' => $sharedKey,
            ],
        ]];
        $this->disconnectParentSqlite();
        $conflicts = $coord->runBarrierWave($conflictWorkers, 60);
        $this->reconnectParentSqlite();

        $payload = $conflicts[0]['result'] ?? null;
        $this->assertNotNull($payload);
        $this->assertFalse($payload['ok'] ?? true);
        if (($payload['error_class'] ?? null) === IdempotencyConflictException::class
            || ($payload['classification'] ?? null) === 'idempotency_conflict') {
            $metrics['expected_domain_conflicts']++;
        } elseif ($this->isSqliteLockFailurePayload($payload)) {
            $metrics['lock_failures']++;
        } else {
            $metrics['unexpected_failures']++;
            $this->fail("Unexpected conflict-wave failure ({$mode}): ".json_encode($payload));
        }

        $this->assertSame(
            $balanceAfter,
            app(AccountBalanceService::class)
                ->getBalanceForLedgerAccount(FinancialAccount::query()->with('ledgerAccount')->findOrFail(self::$accountId)->ledgerAccount)
                ->amountMinor,
        );

        $metrics['integrity_ok'] = true;
        $metrics['notes'] = "created=1 replays={$metrics['idempotent_replays']}";

        return $metrics;
    }

    #[Test]
    #[Depends('test_2_concurrent_same_idempotency_key')]
    public function test_3_concurrent_category_ledger_resolution(): void
    {
        $comparison = [
            'deferred' => $this->runCategoryLedgerWave(false),
            'immediate' => $this->runCategoryLedgerWave(true),
        ];
        self::$modeComparisons['scenario_3_category_ledger'] = $comparison;
        self::$scenarioNotes[] = 's3: '.$this->formatPair($comparison);
        $this->assertGlobalLedgerIntegrity();
    }

    /**
     * @return array<string, mixed>
     */
    private function runCategoryLedgerWave(bool $immediate): array
    {
        $mode = $immediate ? 'immediate' : 'deferred';
        $metrics = $this->emptyMetrics($mode);
        $coord = $this->coordinator($immediate);
        $user = User::query()->findOrFail(self::$userId);

        $category = app(CategoryService::class)->createCategory($user, [
            'name' => 'P4C3 Cat '.$mode.' '.Str::lower(Str::random(6)),
            'type' => CategoryType::Expense,
        ]);
        $categoryId = (int) $category->id;
        $this->assertSame(
            0,
            LedgerAccount::query()->where('category_id', $categoryId)->where('currency_code', 'PHP')->count(),
        );

        $workers = [];
        for ($i = 0; $i < self::WORKER_COUNT; $i++) {
            $workers[] = [
                'id' => "cat_{$mode}_{$i}",
                'action' => 'resolve_category_ledger',
                'payload' => [
                    'user_id' => self::$userId,
                    'category_id' => $categoryId,
                    'currency_code' => 'PHP',
                ],
            ];
        }

        $this->disconnectParentSqlite();
        $results = $coord->runBarrierWave($workers, 90);
        $this->reconnectParentSqlite();

        $ledgerIds = [];
        foreach ($results as $result) {
            $this->assertNotNull($result['result'], $result['output']);
            $payload = $result['result'];
            $metrics['worker_elapsed_ms'][] = (int) ($payload['elapsed_ms'] ?? 0);
            $this->assertSame($immediate, (bool) ($payload['financial_immediate'] ?? false));

            if (! empty($payload['ok'])) {
                $metrics['successes']++;
                $ledgerIds[] = (int) $payload['ledger_account_id'];
                $this->assertSame(0, (int) ($payload['txn_level_after'] ?? -1));

                continue;
            }

            if ($this->isSqliteLockFailurePayload($payload)) {
                $this->assertSqliteLockFailure($payload, "s3 {$mode}");
                $metrics['lock_failures']++;

                continue;
            }

            $metrics['unexpected_failures']++;
            $this->fail("Unexpected category ledger failure ({$mode}): ".json_encode($payload));
        }

        $this->assertGreaterThan(0, $metrics['successes']);
        $this->assertCount(1, array_unique($ledgerIds));
        $this->assertSame(
            1,
            LedgerAccount::query()->where('category_id', $categoryId)->where('currency_code', 'PHP')->count(),
        );

        $metrics['integrity_ok'] = true;
        $metrics['notes'] = 'canonical_ledger='.$ledgerIds[0];

        return $metrics;
    }

    #[Test]
    #[Depends('test_3_concurrent_category_ledger_resolution')]
    public function test_4_concurrent_reversal_same_idempotency_key(): void
    {
        $comparison = [
            'deferred' => $this->runReverseSharedKeyWave(false),
            'immediate' => $this->runReverseSharedKeyWave(true),
        ];
        self::$modeComparisons['scenario_4_reverse_shared_key'] = $comparison;
        self::$scenarioNotes[] = 's4: '.$this->formatPair($comparison);
        $this->assertGlobalLedgerIntegrity();
    }

    /**
     * @return array<string, mixed>
     */
    private function runReverseSharedKeyWave(bool $immediate): array
    {
        $mode = $immediate ? 'immediate' : 'deferred';
        $metrics = $this->emptyMetrics($mode);
        $coord = $this->coordinator($immediate);
        $user = User::query()->findOrFail(self::$userId);

        $created = app(FinancialTransactionService::class)->createTransaction(new CreateFinancialTransactionInput(
            user: $user,
            type: FinancialTransactionType::Expense,
            financialAccountId: self::$accountId,
            amount: '5.00',
            entryDate: '2026-11-03',
            description: 'P4C3 reverse-idem original '.$mode,
            idempotencyKey: (string) Str::uuid(),
            categoryId: self::$categoryId,
        ));
        $originalId = (int) $created->transaction->id;
        $sharedKey = (string) Str::uuid();
        $balanceBefore = app(AccountBalanceService::class)
            ->getBalanceForLedgerAccount(FinancialAccount::query()->with('ledgerAccount')->findOrFail(self::$accountId)->ledgerAccount)
            ->amountMinor;

        $workers = [];
        for ($i = 0; $i < self::WORKER_COUNT; $i++) {
            $workers[] = [
                'id' => "rev_idem_{$mode}_{$i}",
                'action' => 'reverse_transaction',
                'payload' => [
                    'user_id' => self::$userId,
                    'original_transaction_id' => $originalId,
                    'idempotency_key' => $sharedKey,
                    'reason' => 'P4C3 shared reverse '.$mode,
                ],
            ];
        }

        $this->disconnectParentSqlite();
        $results = $coord->runBarrierWave($workers, 90);
        $this->reconnectParentSqlite();

        $reversalIds = [];
        $createdCount = 0;
        foreach ($results as $result) {
            $this->assertNotNull($result['result'], $result['output']);
            $payload = $result['result'];
            $metrics['worker_elapsed_ms'][] = (int) ($payload['elapsed_ms'] ?? 0);

            if (! empty($payload['ok'])) {
                if (! empty($payload['was_recently_created'])) {
                    $createdCount++;
                    $metrics['successes']++;
                } else {
                    $metrics['idempotent_replays']++;
                }
                $reversalIds[] = (int) $payload['reversal_transaction_id'];
                $this->assertSame($payload['debit_sum'], $payload['credit_sum']);
                $this->assertSame(0, (int) ($payload['txn_level_after'] ?? -1));

                continue;
            }

            if (($payload['classification'] ?? null) === 'already_reversed'
                || (($payload['error_class'] ?? null) === LedgerIntegrityException::class
                    && ! empty($payload['already_reversed']))) {
                $metrics['expected_domain_conflicts']++;

                continue;
            }

            if ($this->isSqliteLockFailurePayload($payload)) {
                $this->assertSqliteLockFailure($payload, "s4 {$mode}");
                $metrics['lock_failures']++;

                continue;
            }

            $metrics['unexpected_failures']++;
            $this->fail("Unexpected shared reverse failure ({$mode}): ".json_encode($payload));
        }

        $this->assertSame(1, $createdCount);
        $this->assertCount(1, array_unique($reversalIds));
        $this->assertSame(1, FinancialTransaction::query()->where('reversed_transaction_id', $originalId)->count());
        $this->assertBalancedJournalsForTransactions($reversalIds);

        $balanceAfter = app(AccountBalanceService::class)
            ->getBalanceForLedgerAccount(FinancialAccount::query()->with('ledgerAccount')->findOrFail(self::$accountId)->ledgerAccount)
            ->amountMinor;
        $this->assertSame($balanceBefore + 500, $balanceAfter);
        self::$openingMinor = $balanceAfter;

        $metrics['integrity_ok'] = true;
        $metrics['notes'] = "original={$originalId}";

        return $metrics;
    }

    #[Test]
    #[Depends('test_4_concurrent_reversal_same_idempotency_key')]
    public function test_5_concurrent_reversal_different_keys_one_wins(): void
    {
        $comparison = [
            'deferred' => $this->runReverseDifferentKeysWave(false),
            'immediate' => $this->runReverseDifferentKeysWave(true),
        ];
        self::$modeComparisons['scenario_5_reverse_diff_keys'] = $comparison;
        self::$scenarioNotes[] = 's5: '.$this->formatPair($comparison);
        $this->assertGlobalLedgerIntegrity();
    }

    /**
     * @return array<string, mixed>
     */
    private function runReverseDifferentKeysWave(bool $immediate): array
    {
        $mode = $immediate ? 'immediate' : 'deferred';
        $metrics = $this->emptyMetrics($mode);
        $coord = $this->coordinator($immediate);
        $user = User::query()->findOrFail(self::$userId);

        $created = app(FinancialTransactionService::class)->createTransaction(new CreateFinancialTransactionInput(
            user: $user,
            type: FinancialTransactionType::Expense,
            financialAccountId: self::$accountId,
            amount: '8.00',
            entryDate: '2026-11-03',
            description: 'P4C3 reverse-diff original '.$mode,
            idempotencyKey: (string) Str::uuid(),
            categoryId: self::$categoryId,
        ));
        $originalId = (int) $created->transaction->id;

        $workers = [];
        for ($i = 0; $i < self::WORKER_COUNT; $i++) {
            $workers[] = [
                'id' => "rev_diff_{$mode}_{$i}",
                'action' => 'reverse_transaction',
                'payload' => [
                    'user_id' => self::$userId,
                    'original_transaction_id' => $originalId,
                    'idempotency_key' => (string) Str::uuid(),
                    'reason' => "P4C3 diff reverse {$mode} {$i}",
                ],
            ];
        }

        $this->disconnectParentSqlite();
        $results = $coord->runBarrierWave($workers, 90);
        $this->reconnectParentSqlite();

        $reversalIds = [];
        foreach ($results as $result) {
            $this->assertNotNull($result['result'], $result['output']);
            $payload = $result['result'];
            $metrics['worker_elapsed_ms'][] = (int) ($payload['elapsed_ms'] ?? 0);

            if (! empty($payload['ok'])) {
                $this->assertTrue($payload['was_recently_created'] ?? false);
                $metrics['successes']++;
                $reversalIds[] = (int) $payload['reversal_transaction_id'];

                continue;
            }

            if (($payload['classification'] ?? null) === 'already_reversed'
                || (($payload['error_class'] ?? null) === LedgerIntegrityException::class
                    && ! empty($payload['already_reversed']))) {
                $metrics['expected_domain_conflicts']++;

                continue;
            }

            if (($payload['classification'] ?? null) === 'idempotency_conflict') {
                $metrics['expected_domain_conflicts']++;

                continue;
            }

            if ($this->isSqliteLockFailurePayload($payload)) {
                $this->assertSqliteLockFailure($payload, "s5 {$mode}");
                $metrics['lock_failures']++;

                continue;
            }

            $metrics['unexpected_failures']++;
            $this->fail("Unexpected diff-key reverse failure ({$mode}): ".json_encode($payload));
        }

        $this->assertSame(1, $metrics['successes']);
        $this->assertSame(1, FinancialTransaction::query()->where('reversed_transaction_id', $originalId)->count());
        $this->assertBalancedJournalsForTransactions($reversalIds);
        $this->assertGreaterThan(0, $metrics['expected_domain_conflicts'] + $metrics['lock_failures']);

        self::$openingMinor = app(AccountBalanceService::class)
            ->getBalanceForLedgerAccount(FinancialAccount::query()->with('ledgerAccount')->findOrFail(self::$accountId)->ledgerAccount)
            ->amountMinor;

        $metrics['integrity_ok'] = true;
        $metrics['notes'] = "original={$originalId}";

        return $metrics;
    }

    #[Test]
    #[Depends('test_5_concurrent_reversal_different_keys_one_wins')]
    public function test_6_concurrent_account_creation_same_key_fingerprint(): void
    {
        $comparison = [
            'deferred' => $this->runAccountCreateIdempotencyWave(false),
            'immediate' => $this->runAccountCreateIdempotencyWave(true),
        ];
        self::$modeComparisons['scenario_6_account_create'] = $comparison;
        self::$scenarioNotes[] = 's6: '.$this->formatPair($comparison);
        $this->assertGlobalLedgerIntegrity();
    }

    /**
     * @return array<string, mixed>
     */
    private function runAccountCreateIdempotencyWave(bool $immediate): array
    {
        $mode = $immediate ? 'immediate' : 'deferred';
        $metrics = $this->emptyMetrics($mode);
        $coord = $this->coordinator($immediate);
        $sharedKey = (string) Str::uuid();
        $name = 'P4C3 Acct '.$mode.' '.Str::lower(Str::random(4));

        $workers = [];
        for ($i = 0; $i < self::WORKER_COUNT; $i++) {
            $workers[] = [
                'id' => "acct_{$mode}_{$i}",
                'action' => 'create_account',
                'payload' => [
                    'user_id' => self::$userId,
                    'name' => $name,
                    'type' => 'checking',
                    'currency_code' => 'PHP',
                    'starting_balance' => '100.00',
                    'idempotency_key' => $sharedKey,
                ],
            ];
        }

        $accountsBefore = FinancialAccount::query()->count();

        $this->disconnectParentSqlite();
        $results = $coord->runBarrierWave($workers, 90);
        $this->reconnectParentSqlite();

        $accountIds = [];
        $created = 0;
        foreach ($results as $result) {
            $this->assertNotNull($result['result'], $result['output']);
            $payload = $result['result'];
            $metrics['worker_elapsed_ms'][] = (int) ($payload['elapsed_ms'] ?? 0);

            if (! empty($payload['ok'])) {
                if (! empty($payload['was_recently_created'])) {
                    $created++;
                    $metrics['successes']++;
                } else {
                    $metrics['idempotent_replays']++;
                }
                $accountIds[] = (int) $payload['account_id'];
                $this->assertSame(0, (int) ($payload['txn_level_after'] ?? -1));

                continue;
            }

            if (($payload['classification'] ?? null) === 'idempotency_conflict') {
                $metrics['expected_domain_conflicts']++;

                continue;
            }

            if ($this->isSqliteLockFailurePayload($payload)) {
                $this->assertSqliteLockFailure($payload, "s6 {$mode}");
                $metrics['lock_failures']++;

                continue;
            }

            $metrics['unexpected_failures']++;
            $this->fail("Unexpected account create failure ({$mode}): ".json_encode($payload));
        }

        $this->assertSame(1, $created);
        $this->assertCount(1, array_unique($accountIds));
        $this->assertSame($accountsBefore + 1, FinancialAccount::query()->count());

        $account = FinancialAccount::query()->with('ledgerAccount')->findOrFail($accountIds[0]);
        $this->assertNotNull($account->opening_journal_entry_id);
        $journal = JournalEntry::query()->with('postings')->findOrFail($account->opening_journal_entry_id);
        $this->assertSame(
            (int) $journal->postings->sum('debit_amount_minor'),
            (int) $journal->postings->sum('credit_amount_minor'),
        );

        $metrics['integrity_ok'] = true;
        $metrics['notes'] = 'account_id='.$accountIds[0];

        return $metrics;
    }

    #[Test]
    #[Depends('test_6_concurrent_account_creation_same_key_fingerprint')]
    public function test_7_concurrent_opening_balance_and_equity_resolution(): void
    {
        $comparison = [
            'deferred' => $this->runOpeningBalanceAndEquityWave(false),
            'immediate' => $this->runOpeningBalanceAndEquityWave(true),
        ];
        self::$modeComparisons['scenario_7_opening'] = $comparison;
        self::$scenarioNotes[] = 's7: '.$this->formatPair($comparison);
        $this->assertGlobalLedgerIntegrity();
    }

    /**
     * @return array<string, mixed>
     */
    private function runOpeningBalanceAndEquityWave(bool $immediate): array
    {
        $mode = $immediate ? 'immediate' : 'deferred';
        $metrics = $this->emptyMetrics($mode);
        $coord = $this->coordinator($immediate);
        $user = User::query()->findOrFail(self::$userId);

        // Fresh account without opening so workers race post + equity resolution.
        $account = app(FinancialAccountService::class)->createAccountResult($user, [
            'name' => 'P4C3 Open '.$mode.' '.Str::lower(Str::random(4)),
            'type' => 'checking',
            'currency_code' => 'PHP',
            'idempotency_key' => (string) Str::uuid(),
        ])->account;
        $this->assertNull($account->opening_journal_entry_id);
        $accountId = (int) $account->id;
        $sharedKey = (string) Str::uuid();

        $equityCode = OpeningEquityAccountService::canonicalCode('PHP');
        $equityBefore = LedgerAccount::query()
            ->where('user_id', self::$userId)
            ->where('code', $equityCode)
            ->count();

        $workers = [];
        for ($i = 0; $i < self::WORKER_COUNT; $i++) {
            $workers[] = [
                'id' => "open_{$mode}_{$i}",
                'action' => 'post_opening_balance',
                'payload' => [
                    'user_id' => self::$userId,
                    'financial_account_id' => $accountId,
                    'amount_minor' => 12_345,
                    'entry_date' => '2026-11-03',
                    'idempotency_key' => $sharedKey,
                ],
            ];
        }
        // Parallel pure equity resolvers (same user/currency).
        for ($i = 0; $i < 2; $i++) {
            $workers[] = [
                'id' => "equity_{$mode}_{$i}",
                'action' => 'resolve_opening_equity',
                'payload' => [
                    'user_id' => self::$userId,
                    'currency_code' => 'PHP',
                ],
            ];
        }

        $this->disconnectParentSqlite();
        $results = $coord->runBarrierWave($workers, 120);
        $this->reconnectParentSqlite();

        $openingCreated = 0;
        $openingReplays = 0;
        $equityOk = 0;
        $journalIds = [];

        foreach ($results as $result) {
            $this->assertNotNull($result['result'], $result['output']);
            $payload = $result['result'];
            $metrics['worker_elapsed_ms'][] = (int) ($payload['elapsed_ms'] ?? 0);
            $action = (string) ($payload['action'] ?? '');

            if (! empty($payload['ok'])) {
                if ($action === 'post_opening_balance') {
                    if (! empty($payload['was_recently_created'])) {
                        $openingCreated++;
                        $metrics['successes']++;
                    } else {
                        $openingReplays++;
                        $metrics['idempotent_replays']++;
                    }
                    if (! empty($payload['journal_entry_id'])) {
                        $journalIds[] = (int) $payload['journal_entry_id'];
                    }
                } elseif ($action === 'resolve_opening_equity') {
                    $equityOk++;
                    $metrics['successes']++;
                }

                $this->assertSame(0, (int) ($payload['txn_level_after'] ?? -1));

                continue;
            }

            if (($payload['classification'] ?? null) === 'domain_conflict'
                || ($payload['classification'] ?? null) === 'idempotency_conflict') {
                $metrics['expected_domain_conflicts']++;

                continue;
            }

            if ($this->isSqliteLockFailurePayload($payload)) {
                $this->assertSqliteLockFailure($payload, "s7 {$mode}");
                $metrics['lock_failures']++;

                continue;
            }

            $metrics['unexpected_failures']++;
            $this->fail("Unexpected opening/equity failure ({$mode}): ".json_encode($payload));
        }

        $this->assertSame(1, $openingCreated);
        $account = FinancialAccount::query()->with('ledgerAccount')->findOrFail($accountId);
        $this->assertNotNull($account->opening_journal_entry_id);
        $this->assertSame(
            12_345,
            app(AccountBalanceService::class)->getBalanceForLedgerAccount($account->ledgerAccount)->amountMinor,
        );

        $equityAfter = LedgerAccount::query()
            ->where('user_id', self::$userId)
            ->where('code', $equityCode)
            ->count();
        $this->assertSame(1, $equityAfter, 'Exactly one opening equity account per user/currency.');
        $this->assertLessThanOrEqual(1, max(0, $equityAfter - $equityBefore));
        $this->assertGreaterThan(0, $equityOk);

        if ($journalIds !== []) {
            $journal = JournalEntry::query()->with('postings')->findOrFail($journalIds[0]);
            $this->assertSame(
                (int) $journal->postings->sum('debit_amount_minor'),
                (int) $journal->postings->sum('credit_amount_minor'),
            );
        }

        $metrics['integrity_ok'] = true;
        $metrics['notes'] = "opening_created={$openingCreated} replays={$openingReplays} equity_ok={$equityOk}";

        return $metrics;
    }

    #[Test]
    #[Depends('test_7_concurrent_opening_balance_and_equity_resolution')]
    public function test_8_held_writer_immediate_contention_and_retry(): void
    {
        // DEFERRED: contention comparison only (not used to prove IMMEDIATE retry recovery).
        $deferred = $this->runHeldWriterContender(false, 'compare');
        // IMMEDIATE recovery: hold past one busy_timeout, release, require retry then success.
        $recovery = $this->runHeldWriterContender(true, 'recovery');
        // IMMEDIATE exhaustion: keep hold until contender exits; require attempts==3 lock failure.
        $exhaustion = $this->runHeldWriterContender(true, 'exhaustion');

        self::$modeComparisons['scenario_8_held_writer'] = [
            'deferred' => $deferred,
            // Primary immediate signal for the deferred/immediate summary pair is recovery.
            'immediate' => $recovery,
        ];
        // Exhaustion is asserted inline; recorded in notes only (not the deferred/immediate pair map).
        $this->assertSame(0, $exhaustion['unexpected_failures']);
        $this->assertTrue($exhaustion['integrity_ok'] ?? false);

        self::$scenarioNotes[] = 's8: deferred='.$this->formatHeldNote($deferred)
            .' | recovery='.$this->formatHeldNote($recovery)
            .' | exhaustion='.$this->formatHeldNote($exhaustion);
        $this->assertGlobalLedgerIntegrity();
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function formatHeldNote(array $metrics): string
    {
        return ($metrics['variant'] ?? '?')
            .' ok='.$metrics['successes']
            .' lock='.$metrics['lock_failures']
            .' attempts='.json_encode($metrics['attempts'] ?? null)
            .' elapsed='.json_encode($metrics['worker_elapsed_ms'] ?? []);
    }

    /**
     * Held writer vs financial contender.
     *
     * @param  'compare'|'recovery'|'exhaustion'  $variant
     * @return array<string, mixed>
     */
    private function runHeldWriterContender(bool $immediate, string $variant): array
    {
        $this->assertContains($variant, ['compare', 'recovery', 'exhaustion']);
        $mode = $immediate ? 'immediate' : 'deferred';
        $metrics = $this->emptyMetrics($mode);
        $metrics['worker_count'] = 2;
        $metrics['variant'] = $variant;
        $metrics['attempts'] = null;
        $coord = $this->coordinator($immediate);
        $token = Phase4C3Isolation::runToken();
        $heldKey = "held_{$variant}_{$mode}_{$token}";
        $releaseKey = "release_{$variant}_{$mode}_{$token}";
        $attemptingKey = "attempting_{$variant}_{$mode}_{$token}";
        $barrier = new Phase3Barrier(
            Phase4C3Isolation::workDir().DIRECTORY_SEPARATOR.'barrier-'.$token.'.sqlite',
        );

        $balanceBefore = app(AccountBalanceService::class)
            ->getBalanceForLedgerAccount(FinancialAccount::query()->with('ledgerAccount')->findOrFail(self::$accountId)->ledgerAccount)
            ->amountMinor;
        $txnsBefore = FinancialTransaction::query()->count();

        $holder = null;
        $contender = null;
        $holderResult = null;
        $contenderResult = null;

        $this->disconnectParentSqlite();
        try {
            $holder = $coord->spawnWorker("holder_{$variant}_{$mode}", 'hold_immediate_writer', [
                'held_key' => $heldKey,
                'release_key' => $releaseKey,
            ]);
            $coord->waitForBarrierKey($heldKey, '1', 30);

            $contender = $coord->spawnWorker("contender_{$variant}_{$mode}", 'post_expense', [
                'user_id' => self::$userId,
                'financial_account_id' => self::$accountId,
                'category_id' => self::$categoryId,
                'amount' => '3.00',
                'entry_date' => '2026-11-03',
                'description' => "P4C3 held-contender {$variant} {$mode}",
                'idempotency_key' => (string) Str::uuid(),
                'attempting_key' => $attemptingKey,
            ]);

            if ($variant === 'exhaustion') {
                // Keep the write lock until the contender finishes (retry exhaustion under IMMEDIATE).
                $contenderResult = $coord->awaitOwnedWorker($contender, 120);
                $contender = null;
                $barrier->set($releaseKey, '1');
                $holderResult = $coord->awaitOwnedWorker($holder, 60);
                $holder = null;
            } else {
                // Start the busy_timeout hold only after the contender is about to BEGIN (not during boot).
                // Hold past DB_BUSY_TIMEOUT (5000ms) with margin so attempt 1 fails before release.
                $coord->waitForBarrierKey($attemptingKey, '1', 60);
                usleep(7_000_000);
                $barrier->set($releaseKey, '1');
                $holderResult = $coord->awaitOwnedWorker($holder, 60);
                $holder = null;
                $contenderResult = $coord->awaitOwnedWorker($contender, 90);
                $contender = null;
            }
        } catch (\Throwable $e) {
            try {
                $barrier->set($releaseKey, '1');
            } catch (\Throwable) {
            }
            if ($holder !== null) {
                $coord->cleanupOwnedWorker($holder);
            }
            if ($contender !== null) {
                $coord->cleanupOwnedWorker($contender);
            }
            throw $e;
        } finally {
            $this->reconnectParentSqlite();
        }

        $this->assertNotNull($holderResult);
        $this->assertNotNull($contenderResult);
        $this->assertTrue($holderResult['result']['ok'] ?? false, json_encode($holderResult));

        $payload = $contenderResult['result'];
        $this->assertNotNull($payload, $contenderResult['output']);
        $metrics['worker_elapsed_ms'][] = (int) ($payload['elapsed_ms'] ?? 0);
        $metrics['attempts'] = isset($payload['attempts']) ? (int) $payload['attempts'] : null;
        $this->assertSame($immediate, (bool) ($payload['financial_immediate'] ?? false));
        $this->assertArrayHasKey('attempts', $payload, 'Worker must report test-only attempt instrumentation');
        $this->assertArrayHasKey('retry_pauses', $payload);

        if ($variant === 'recovery') {
            $this->assertTrue($immediate);
            $this->assertTrue(! empty($payload['ok']), 'IMMEDIATE recovery must succeed after retry: '.json_encode($payload));
            $this->assertGreaterThanOrEqual(2, (int) $payload['attempts'], 'Recovery must observe at least one failed attempt before success');
            $this->assertGreaterThanOrEqual(1, (int) $payload['retry_pauses']);
            $this->assertGreaterThanOrEqual(5000, (int) ($payload['elapsed_ms'] ?? 0));
            $metrics['successes']++;
            $this->assertTrue($payload['was_recently_created'] ?? false);
            $this->assertSame($payload['debit_sum'], $payload['credit_sum']);
            $this->assertSame(0, (int) ($payload['txn_level_after'] ?? -1));
            $this->assertBalancedJournalsForTransactions([(int) $payload['transaction_id']]);
            $this->assertSame($txnsBefore + 1, FinancialTransaction::query()->count());
            $this->assertSame(
                $balanceBefore - 300,
                app(AccountBalanceService::class)
                    ->getBalanceForLedgerAccount(FinancialAccount::query()->with('ledgerAccount')->findOrFail(self::$accountId)->ledgerAccount)
                    ->amountMinor,
            );
            self::$openingMinor = $balanceBefore - 300;
        } elseif ($variant === 'exhaustion') {
            $this->assertTrue($immediate);
            $this->assertTrue(empty($payload['ok']), 'IMMEDIATE exhaustion must not commit: '.json_encode($payload));
            $this->assertSqliteLockFailure($payload, 's8 exhaustion');
            $this->assertSame(3, (int) $payload['attempts'], 'Exhaustion must use all three concurrency attempts');
            $this->assertSame(2, (int) $payload['retry_pauses']);
            $this->assertSame(0, (int) ($payload['txn_level_after'] ?? -1));
            $metrics['lock_failures']++;
            $this->assertSame($txnsBefore, FinancialTransaction::query()->count());
            $this->assertSame(
                $balanceBefore,
                app(AccountBalanceService::class)
                    ->getBalanceForLedgerAccount(FinancialAccount::query()->with('ledgerAccount')->findOrFail(self::$accountId)->ledgerAccount)
                    ->amountMinor,
            );
        } elseif (! empty($payload['ok'])) {
            $metrics['successes']++;
            $this->assertTrue($payload['was_recently_created'] ?? false);
            $this->assertSame($payload['debit_sum'], $payload['credit_sum']);
            $this->assertSame(0, (int) ($payload['txn_level_after'] ?? -1));
            $this->assertBalancedJournalsForTransactions([(int) $payload['transaction_id']]);
            $this->assertSame($txnsBefore + 1, FinancialTransaction::query()->count());
            $this->assertSame(
                $balanceBefore - 300,
                app(AccountBalanceService::class)
                    ->getBalanceForLedgerAccount(FinancialAccount::query()->with('ledgerAccount')->findOrFail(self::$accountId)->ledgerAccount)
                    ->amountMinor,
            );
            self::$openingMinor = $balanceBefore - 300;
        } elseif ($this->isSqliteLockFailurePayload($payload)) {
            $this->assertSqliteLockFailure($payload, "s8 {$mode} {$variant}");
            $this->assertSame(0, (int) ($payload['txn_level_after'] ?? -1));
            $metrics['lock_failures']++;
            $this->assertSame($txnsBefore, FinancialTransaction::query()->count());
            $this->assertSame(
                $balanceBefore,
                app(AccountBalanceService::class)
                    ->getBalanceForLedgerAccount(FinancialAccount::query()->with('ledgerAccount')->findOrFail(self::$accountId)->ledgerAccount)
                    ->amountMinor,
            );
        } else {
            $metrics['unexpected_failures']++;
            $this->fail("Unexpected held-contender failure ({$mode}/{$variant}): ".json_encode($payload));
        }

        $metrics['integrity_ok'] = true;
        $metrics['notes'] = 'variant='.$variant
            .' elapsed_ms='.(int) ($payload['elapsed_ms'] ?? 0)
            .' attempts='.(int) ($payload['attempts'] ?? 0)
            .' ok='.(! empty($payload['ok']) ? '1' : '0');

        return $metrics;
    }

    #[Test]
    #[Depends('test_8_held_writer_immediate_contention_and_retry')]
    public function test_9_comparison_summary_and_final_integrity(): void
    {
        $this->assertNotEmpty(self::$modeComparisons);
        $this->assertGlobalLedgerIntegrity();

        foreach (self::$modeComparisons as $scenario => $pair) {
            foreach (['deferred', 'immediate'] as $mode) {
                $m = $pair[$mode];
                $this->assertSame(0, $m['unexpected_failures'], "{$scenario}/{$mode}: ".json_encode($m));
                $this->assertTrue($m['integrity_ok'] ?? false, "{$scenario}/{$mode} integrity");
                $accounted = $m['successes']
                    + $m['idempotent_replays']
                    + $m['expected_domain_conflicts']
                    + $m['lock_failures']
                    + $m['unexpected_failures'];
                // Scenario 7 mixes opening + equity workers; scenario 8 uses 2 workers.
                $this->assertGreaterThan(0, $accounted, "{$scenario}/{$mode} produced no outcomes");
            }
        }

        // Parent never enables production IMMEDIATE.
        $this->assertFalse((bool) config('equity.sqlite.financial_immediate'));

        $summary = [
            'comparisons' => self::$modeComparisons,
            'notes' => self::$scenarioNotes,
            'posting_count' => JournalPosting::query()->count(),
            'journal_count' => JournalEntry::query()->count(),
            'txn_count' => FinancialTransaction::query()->count(),
            'claim' => 'IMMEDIATE reduces writer races via reserved write lock at BEGIN; it does not eliminate all SQLITE_BUSY outcomes under sustained contention.',
        ];

        fwrite(STDOUT, 'Phase4C3 DEFERRED vs IMMEDIATE comparison: '.json_encode($summary)."\n");
        fwrite(STDOUT, 'Phase4C3 scenario notes: '.implode(' | ', self::$scenarioNotes)."\n");
    }

    /**
     * @param  array{deferred: array<string, mixed>, immediate: array<string, mixed>}  $pair
     */
    private function formatPair(array $pair): string
    {
        $parts = [];
        foreach (['deferred', 'immediate'] as $mode) {
            $m = $pair[$mode];
            $parts[] = $mode
                .' ok='.$m['successes']
                .' replay='.$m['idempotent_replays']
                .' conflict='.$m['expected_domain_conflicts']
                .' lock='.$m['lock_failures']
                .' unexpected='.$m['unexpected_failures'];
        }

        return implode('; ', $parts);
    }
}
