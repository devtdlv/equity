<?php

namespace App\Database\SQLite;

use PDO;
use PDOException;

/**
 * PDO subclass that can begin SQLite transactions as DEFERRED (default) or IMMEDIATE
 * while keeping PDO::inTransaction() / commit() / rollBack() consistent on PHP 8.2.
 *
 * Constructed by {@see EquitySQLiteConnector} so Laravel pragmas and options apply
 * to this instance. Existing PDO objects cannot be converted into this subclass.
 *
 * Failed COMMIT/ROLLBACK: SQLite may leave the transaction active (notably SQLITE_BUSY
 * on COMMIT while readers hold locks). This class does not clear immediateTransactionOpen
 * unless SQLite reports that no transaction remains. PHP 8.2 PDO does not expose
 * sqlite3_get_autocommit(); reconciliation uses error text plus a conservative keep-open
 * default for recoverable failures.
 */
final class ImmediateAwareSqlitePdo extends PDO
{
    public const MODE_DEFERRED = 'DEFERRED';

    public const MODE_IMMEDIATE = 'IMMEDIATE';

    private bool $immediateTransactionOpen = false;

    private string $pendingBeginMode = self::MODE_DEFERRED;

    /** @var int Construction counter for connector-hook verification in tests. */
    private static int $instancesConstructed = 0;

    public function __construct(string $dsn, ?string $username = null, #[\SensitiveParameter] ?string $password = null, ?array $options = null)
    {
        parent::__construct($dsn, $username, $password, $options ?? []);
        self::$instancesConstructed++;
    }

    public static function instancesConstructed(): int
    {
        return self::$instancesConstructed;
    }

    public static function resetInstancesConstructed(): void
    {
        self::$instancesConstructed = 0;
    }

    public function setPendingBeginMode(string $mode): void
    {
        $normalized = strtoupper(trim($mode));
        if (! in_array($normalized, [self::MODE_DEFERRED, self::MODE_IMMEDIATE], true)) {
            throw new PDOException('Unsupported SQLite begin mode: '.$mode);
        }

        $this->pendingBeginMode = $normalized;
    }

    public function clearPendingBeginMode(): void
    {
        $this->pendingBeginMode = self::MODE_DEFERRED;
    }

    public function pendingBeginMode(): string
    {
        return $this->pendingBeginMode;
    }

    public function immediateTransactionOpen(): bool
    {
        return $this->immediateTransactionOpen;
    }

    public function beginTransaction(): bool
    {
        if ($this->immediateTransactionOpen || parent::inTransaction()) {
            throw new PDOException('There is already an active transaction');
        }

        $mode = $this->pendingBeginMode;
        $this->pendingBeginMode = self::MODE_DEFERRED;

        if ($mode === self::MODE_IMMEDIATE) {
            try {
                $this->exec('BEGIN IMMEDIATE TRANSACTION');
            } catch (PDOException $e) {
                // BEGIN failed: no IMMEDIATE txn was established.
                $this->immediateTransactionOpen = false;
                throw $e;
            }
            $this->immediateTransactionOpen = true;

            return true;
        }

        return parent::beginTransaction();
    }

    public function commit(): bool
    {
        if (! $this->immediateTransactionOpen) {
            return parent::commit();
        }

        try {
            $this->exec('COMMIT');
        } catch (PDOException $e) {
            $this->reconcileImmediateFlagAfterFailedTerminalStatement($e, 'commit');
            throw $e;
        }

        $this->immediateTransactionOpen = false;

        return true;
    }

    public function rollBack(): bool
    {
        if (! $this->immediateTransactionOpen) {
            return parent::rollBack();
        }

        try {
            $this->exec('ROLLBACK');
        } catch (PDOException $e) {
            $this->reconcileImmediateFlagAfterFailedTerminalStatement($e, 'rollback');
            throw $e;
        }

        $this->immediateTransactionOpen = false;

        return true;
    }

    public function inTransaction(): bool
    {
        return $this->immediateTransactionOpen || parent::inTransaction();
    }

    /**
     * After a failed COMMIT/ROLLBACK, clear our flag only when SQLite indicates the
     * transaction is gone. SQLITE_BUSY on COMMIT leaves the txn active — keep the flag.
     *
     * Limitation (PHP 8.2 PDO): without sqlite3_get_autocommit(), ambiguous failures
     * keep the flag set (fail closed on state) rather than silently marking closed.
     */
    private function reconcileImmediateFlagAfterFailedTerminalStatement(PDOException $e, string $op): void
    {
        $message = strtolower($e->getMessage());

        // Explicit "no transaction" means SQLite already ended the txn (or never had one).
        if (
            str_contains($message, 'no transaction is active')
            || str_contains($message, 'there is no active transaction')
            || (
                str_contains($message, 'cannot '.$op)
                && str_contains($message, 'no transaction')
            )
        ) {
            $this->immediateTransactionOpen = false;

            return;
        }

        // Recoverable lock on COMMIT: transaction remains active per SQLite docs.
        // Keep immediateTransactionOpen = true.
        // For any other failure without proof the txn ended: keep flag set.
    }
}
