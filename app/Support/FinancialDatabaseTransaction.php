<?php

namespace App\Support;

use App\Database\SQLite\EquitySQLiteConnection;
use App\Database\SQLite\ImmediateAwareSqlitePdo;
use App\Exceptions\FinancialImmediateTransactionConflictException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;
use RuntimeException;

/**
 * Explicit outermost financial write transaction helper.
 *
 * When config equity.sqlite.financial_immediate is true and the driver is sqlite,
 * requests BEGIN IMMEDIATE for the next outermost begin only. Nested savepoints are
 * allowed under an existing IMMEDIATE outer transaction. Nested DEFERRED outers fail closed.
 *
 * Does not change MariaDB/MySQL behavior. Does not alter retry counts in financial services.
 */
final class FinancialDatabaseTransaction
{
    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function run(callable $callback): mixed
    {
        return self::runOnConnection(DB::connection(), $callback);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function runOnConnection(Connection $connection, callable $callback): mixed
    {
        if ($connection->getDriverName() !== 'sqlite') {
            return $connection->transaction($callback);
        }

        $immediateEnabled = (bool) config('equity.sqlite.financial_immediate', false);

        if ($connection->transactionLevel() > 0) {
            return self::runNested($connection, $callback, $immediateEnabled);
        }

        if (! $immediateEnabled) {
            return $connection->transaction($callback);
        }

        self::assertEquityImmediateInfrastructure($connection);

        /** @var EquitySQLiteConnection $connection */
        $connection->requestImmediateForNextOutermostBegin();

        try {
            return $connection->transaction($callback);
        } catch (PDOException $e) {
            throw self::translateBeginBusyIfNeeded($connection, $e);
        } finally {
            // Always clear leftover BEGIN-failure latch (e.g. non-PDO Throwable after a
            // failed IMMEDIATE begin). translateBeginBusyIfNeeded already consumes when
            // it runs; a second consume is a no-op.
            $connection->consumeImmediateBeginFailurePending();
            $connection->clearNextOutermostMode();
            if ($connection->transactionLevel() === 0) {
                $connection->clearOutermostBeginMode();
            }
            $pdo = $connection->getRawPdo();
            if ($pdo instanceof ImmediateAwareSqlitePdo) {
                $pdo->clearPendingBeginMode();
            }
        }
    }

    /**
     * Fail closed when IMMEDIATE is enabled but the live connection/PDO is not Equity's.
     */
    public static function assertEquityImmediateInfrastructure(Connection $connection): void
    {
        if (! $connection instanceof EquitySQLiteConnection) {
            throw new RuntimeException(
                'Financial IMMEDIATE requires EquitySQLiteConnection; got '.$connection::class
                .'. An incompatible sqlite Connection resolver may be registered.',
            );
        }

        // Force lazy connect so we validate the real PDO, not only the Connection class.
        $pdo = $connection->getPdo();
        if (! $pdo instanceof ImmediateAwareSqlitePdo) {
            throw new RuntimeException(
                'Financial IMMEDIATE requires ImmediateAwareSqlitePdo; got '.$pdo::class
                .'. EquitySQLiteConnector must construct the PDO.',
            );
        }
    }

    /**
     * When equity.sqlite.financial_immediate is enabled on SQLite, joining an existing
     * transaction is allowed only under an Equity IMMEDIATE outermost begin.
     *
     * No-op for non-sqlite drivers and when the flag is off. Does not open a transaction
     * or savepoint — callers that join keep their existing in-transaction semantics.
     *
     * @throws FinancialImmediateTransactionConflictException
     */
    public static function assertCompatibleImmediateOuterTransaction(?Connection $connection = null): void
    {
        $connection ??= DB::connection();

        if ($connection->getDriverName() !== 'sqlite') {
            return;
        }

        if (! (bool) config('equity.sqlite.financial_immediate', false)) {
            return;
        }

        if (! $connection instanceof EquitySQLiteConnection) {
            throw new FinancialImmediateTransactionConflictException(
                'Financial IMMEDIATE write cannot nest under an unknown outer SQLite transaction.',
            );
        }

        if ($connection->outermostBeginMode() === EquitySQLiteConnection::MODE_IMMEDIATE) {
            return;
        }

        throw new FinancialImmediateTransactionConflictException(
            'Financial IMMEDIATE write cannot nest inside a DEFERRED (or unknown) outer transaction. '
            .'Restructure the caller to use FinancialDatabaseTransaction for the outermost write.',
        );
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private static function runNested(Connection $connection, callable $callback, bool $immediateEnabled): mixed
    {
        if (! $immediateEnabled) {
            // Flag OFF: preserve historical nested savepoint behavior.
            return $connection->transaction($callback);
        }

        self::assertCompatibleImmediateOuterTransaction($connection);

        // Savepoint only — Laravel will not issue another BEGIN.
        return $connection->transaction($callback);
    }

    /**
     * Translate only confirmed BEGIN IMMEDIATE lock failures (marker from EquitySQLiteConnection).
     * Unrelated SQLITE_BUSY at level 0 is left as PDOException.
     */
    private static function translateBeginBusyIfNeeded(
        EquitySQLiteConnection $connection,
        PDOException $e,
    ): PDOException|QueryException {
        if ($e instanceof QueryException) {
            // Still consume a stale marker if present.
            $connection->consumeImmediateBeginFailurePending();

            return $e;
        }

        $confirmedBeginFailure = $connection->consumeImmediateBeginFailurePending();

        if (! $confirmedBeginFailure) {
            return $e;
        }

        if (! self::isSqliteBusyOrLocked($e)) {
            return $e;
        }

        return new QueryException(
            (string) $connection->getName(),
            'BEGIN IMMEDIATE TRANSACTION',
            [],
            $e,
            [
                'driver' => $connection->getDriverName(),
                'database' => $connection->getDatabaseName(),
            ],
        );
    }

    private static function isSqliteBusyOrLocked(PDOException $e): bool
    {
        $errorInfo = is_array($e->errorInfo ?? null) ? $e->errorInfo : [];
        $driverCode = (int) ($errorInfo[1] ?? 0);

        if (in_array($driverCode, [
            TransientTransactionFailure::SQLITE_BUSY,
            TransientTransactionFailure::SQLITE_LOCKED,
        ], true)) {
            return true;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'database is locked')
            || str_contains($message, 'sqlite_busy')
            || str_contains($message, 'sqlite_locked');
    }
}
