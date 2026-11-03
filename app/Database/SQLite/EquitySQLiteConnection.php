<?php

namespace App\Database\SQLite;

use Closure;
use Illuminate\Database\SQLiteConnection;
use Throwable;

/**
 * SQLite connection that can request BEGIN IMMEDIATE for the next outermost begin only.
 *
 * Nested levels use Laravel savepoints and never re-enter executeBeginTransactionStatement
 * for a second BEGIN. Mode request state is cleared after begin attempt, on setPdo
 * (reconnect), and when the outermost transaction returns to level 0.
 */
final class EquitySQLiteConnection extends SQLiteConnection
{
    public const MODE_DEFERRED = ImmediateAwareSqlitePdo::MODE_DEFERRED;

    public const MODE_IMMEDIATE = ImmediateAwareSqlitePdo::MODE_IMMEDIATE;

    private ?string $nextOutermostMode = null;

    private ?string $outermostBeginMode = null;

    /**
     * Set only while executeBeginTransactionStatement is attempting BEGIN IMMEDIATE,
     * or latched true when that attempt fails — consumed by FinancialDatabaseTransaction.
     */
    private bool $immediateBeginFailurePending = false;

    private bool $executingImmediateBegin = false;

    public function requestImmediateForNextOutermostBegin(): void
    {
        $this->nextOutermostMode = self::MODE_IMMEDIATE;
    }

    public function clearNextOutermostMode(): void
    {
        $this->nextOutermostMode = null;
    }

    public function nextOutermostMode(): ?string
    {
        return $this->nextOutermostMode;
    }

    public function outermostBeginMode(): ?string
    {
        return $this->outermostBeginMode;
    }

    public function clearOutermostBeginMode(): void
    {
        $this->outermostBeginMode = null;
    }

    /**
     * Atomically read-and-clear whether the last outermost begin attempt was a failed
     * BEGIN IMMEDIATE (for narrow QueryException translation).
     */
    public function consumeImmediateBeginFailurePending(): bool
    {
        $pending = $this->immediateBeginFailurePending;
        $this->immediateBeginFailurePending = false;

        return $pending;
    }

    public function isExecutingImmediateBegin(): bool
    {
        return $this->executingImmediateBegin;
    }

    /**
     * @param  \PDO|\Closure|null  $pdo
     * @return $this
     */
    public function setPdo($pdo)
    {
        $this->nextOutermostMode = null;
        $this->outermostBeginMode = null;
        $this->immediateBeginFailurePending = false;
        $this->executingImmediateBegin = false;

        if ($pdo instanceof ImmediateAwareSqlitePdo) {
            $pdo->clearPendingBeginMode();
        }

        return parent::setPdo($pdo);
    }

    protected function executeBeginTransactionStatement()
    {
        $mode = $this->nextOutermostMode ?? self::MODE_DEFERRED;
        $this->nextOutermostMode = null;
        $this->immediateBeginFailurePending = false;

        $pdo = $this->getPdo();

        if ($mode === self::MODE_IMMEDIATE) {
            if (! $pdo instanceof ImmediateAwareSqlitePdo) {
                throw new \RuntimeException(
                    'IMMEDIATE begin requested but PDO is not ImmediateAwareSqlitePdo (got '
                    .$pdo::class.').',
                );
            }
            $pdo->setPendingBeginMode(self::MODE_IMMEDIATE);
            $this->executingImmediateBegin = true;
        } elseif ($pdo instanceof ImmediateAwareSqlitePdo) {
            $pdo->setPendingBeginMode(self::MODE_DEFERRED);
        }

        try {
            $pdo->beginTransaction();
            $this->outermostBeginMode = $mode;
        } catch (Throwable $e) {
            $this->outermostBeginMode = null;
            // Latch only PDOException: FinancialDatabaseTransaction translates busy/locked
            // PDO failures. Non-PDO Throwables must not leave a translation marker.
            if ($mode === self::MODE_IMMEDIATE && $e instanceof \PDOException) {
                $this->immediateBeginFailurePending = true;
            }
            if ($pdo instanceof ImmediateAwareSqlitePdo) {
                $pdo->clearPendingBeginMode();
            }
            throw $e;
        } finally {
            $this->executingImmediateBegin = false;
        }
    }

    /**
     * Laravel's transaction() commits via PDO directly (not $this->commit()), so clear
     * outermost mode here whenever the connection returns to level 0.
     *
     * @param  (\Closure(static): mixed)  $callback
     * @param  int  $attempts
     * @return mixed
     */
    public function transaction(Closure $callback, $attempts = 1)
    {
        try {
            return parent::transaction($callback, $attempts);
        } finally {
            if ($this->transactionLevel() === 0) {
                $this->outermostBeginMode = null;
            }
        }
    }

    public function commit()
    {
        parent::commit();

        if ($this->transactionLevel() === 0) {
            $this->outermostBeginMode = null;
        }
    }

    public function rollBack($toLevel = null)
    {
        parent::rollBack($toLevel);

        if ($this->transactionLevel() === 0) {
            $this->outermostBeginMode = null;
        }
    }
}
