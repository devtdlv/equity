<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Classifies transient transaction failures that warrant a full application-level retry.
 *
 * Distinct from {@see IdempotencyUniqueConstraint}: duplicate-key recovery is not a
 * deadlock/lock-timeout retry.
 */
final class TransientTransactionFailure
{
    /**
     * MariaDB/MySQL ER_LOCK_DEADLOCK.
     */
    public const MARIADB_DEADLOCK = 1213;

    /**
     * MariaDB/MySQL ER_LOCK_WAIT_TIMEOUT.
     */
    public const MARIADB_LOCK_WAIT_TIMEOUT = 1205;

    /**
     * SQLite SQLITE_BUSY.
     */
    public const SQLITE_BUSY = 5;

    /**
     * SQLite SQLITE_LOCKED.
     */
    public const SQLITE_LOCKED = 6;

    public static function isRetryable(QueryException $exception): bool
    {
        // Unique violations have their own recovery path; never treat them as deadlocks.
        if ($exception instanceof UniqueConstraintViolationException) {
            return false;
        }

        if (IdempotencyUniqueConstraint::isDuplicateKeyViolation($exception)) {
            return false;
        }

        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        if ($driverCode === self::MARIADB_DEADLOCK) {
            return true;
        }

        if ($driverCode === self::MARIADB_LOCK_WAIT_TIMEOUT) {
            return true;
        }

        if ($driverCode === self::SQLITE_BUSY || $driverCode === self::SQLITE_LOCKED) {
            return true;
        }

        // Serialization failure / deadlock SQLSTATEs (MariaDB 40001, PostgreSQL 40P01).
        if ($sqlState === '40001' || $sqlState === '40P01') {
            return true;
        }

        return false;
    }
}
