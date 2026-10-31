<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Classifies genuine duplicate-key violations for user/idempotency unique indexes.
 *
 * Driver codes are the primary signal. Exception SQL text is only inspected for the
 * violated constraint identity after a unique violation is confirmed — never by the
 * mere presence of an "idempotency_key" column in the attempted INSERT.
 */
final class IdempotencyUniqueConstraint
{
    /**
     * MariaDB/MySQL ER_DUP_ENTRY.
     */
    public const MARIADB_DUPLICATE_KEY = 1062;

    /**
     * SQLite SQLITE_CONSTRAINT_UNIQUE (extended result code).
     */
    public const SQLITE_CONSTRAINT_UNIQUE = 2067;

    /**
     * PostgreSQL unique_violation.
     */
    public const POSTGRES_UNIQUE_SQLSTATE = '23505';

    public static function matches(
        QueryException $exception,
        string $indexName,
        string $tableName,
    ): bool {
        if (! self::isDuplicateKeyViolation($exception)) {
            return false;
        }

        return self::matchesTargetConstraint($exception, $indexName, $tableName);
    }

    public static function isDuplicateKeyViolation(QueryException $exception): bool
    {
        if ($exception instanceof UniqueConstraintViolationException) {
            return true;
        }

        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        if ($driverCode === self::MARIADB_DUPLICATE_KEY) {
            return true;
        }

        if ($driverCode === self::SQLITE_CONSTRAINT_UNIQUE) {
            return true;
        }

        if ($sqlState === self::POSTGRES_UNIQUE_SQLSTATE) {
            return true;
        }

        // SQLSTATE 23000 and SQLite generic constraint code 19 are insufficient alone:
        // they also cover foreign-key, CHECK, and NOT NULL failures.
        return false;
    }

    private static function matchesTargetConstraint(
        QueryException $exception,
        string $indexName,
        string $tableName,
    ): bool {
        $message = strtolower($exception->getMessage());
        $index = strtolower($indexName);
        $table = strtolower($tableName);

        // MariaDB 1062: Duplicate entry '...' for key 'index_name'
        if (str_contains($message, $index)) {
            return true;
        }

        // SQLite extended unique failure names the constrained columns.
        // Require an explicit unique-failure phrase so INSERT SQL listing the
        // idempotency_key column cannot satisfy this check by itself.
        $uniqueFailed = str_contains($message, 'unique constraint failed')
            || str_contains($message, 'duplicate entry')
            || str_contains($message, 'unique violation');

        if (! $uniqueFailed) {
            return false;
        }

        $hasTable = str_contains($message, $table);
        $hasUserId = str_contains($message, $table.'.user_id')
            || (str_contains($message, 'user_id') && $hasTable);
        $hasIdempotencyKey = str_contains($message, $table.'.idempotency_key')
            || (str_contains($message, 'idempotency_key') && $hasTable);

        return $hasTable && $hasUserId && $hasIdempotencyKey;
    }
}
