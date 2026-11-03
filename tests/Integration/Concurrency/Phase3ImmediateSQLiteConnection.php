<?php

namespace Tests\Integration\Concurrency;

use Illuminate\Database\SQLiteConnection;

/**
 * Test-only SQLite connection that begins outermost transactions with BEGIN IMMEDIATE.
 *
 * Nested levels still use Laravel savepoints via ManagesTransactions. Commit, rollback,
 * transactionLevel, and exception handling remain on the parent Connection path, which
 * calls PDO begin/commit/rollBack — those are implemented by Phase3ImmediateSqlitePdo so
 * PDO's inTransaction flag stays consistent on PHP 8.2.
 *
 * Registered only in Phase 3 worker processes when EQUITY_PHASE3_SQLITE_TXN_MODE=immediate.
 */
final class Phase3ImmediateSQLiteConnection extends SQLiteConnection
{
    private static int $immediateBeginCount = 0;

    /** @var list<int> */
    private static array $beginWaitSamplesMs = [];

    public static function resetBeginMetrics(): void
    {
        self::$immediateBeginCount = 0;
        self::$beginWaitSamplesMs = [];
    }

    public static function immediateBeginCount(): int
    {
        return self::$immediateBeginCount;
    }

    /**
     * @return list<int>
     */
    public static function beginWaitSamplesMs(): array
    {
        return self::$beginWaitSamplesMs;
    }

    /**
     * Outermost begin only — ManagesTransactions calls this when transactions == 0.
     * Nested begins use createSavepoint() and never reach here.
     */
    protected function executeBeginTransactionStatement()
    {
        $started = microtime(true);
        // Phase3ImmediateSqlitePdo::beginTransaction issues BEGIN IMMEDIATE and
        // keeps PDO commit/rollBack/inTransaction consistent for Laravel.
        $this->getPdo()->beginTransaction();
        $waitMs = (int) round((microtime(true) - $started) * 1000);
        self::$immediateBeginCount++;
        self::$beginWaitSamplesMs[] = $waitMs;
    }
}
