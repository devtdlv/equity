<?php

namespace App\Database\SQLite;

use Illuminate\Database\Connectors\SQLiteConnector;

/**
 * SQLite connector that constructs {@see ImmediateAwareSqlitePdo} instead of stock PDO.
 *
 * All parent connect() behavior is preserved: path parsing, PDO options, and pragmas
 * (foreign_keys, busy_timeout, journal_mode, synchronous, custom pragmas).
 */
final class EquitySQLiteConnector extends SQLiteConnector
{
    /** @var int */
    private static int $createPdoConnectionCalls = 0;

    public static function createPdoConnectionCalls(): int
    {
        return self::$createPdoConnectionCalls;
    }

    public static function resetCreatePdoConnectionCalls(): void
    {
        self::$createPdoConnectionCalls = 0;
    }

    /**
     * @param  string  $dsn
     * @param  string|null  $username
     * @param  string|null  $password
     * @param  array  $options
     * @return \PDO
     */
    protected function createPdoConnection($dsn, $username, #[\SensitiveParameter] $password, $options)
    {
        self::$createPdoConnectionCalls++;

        return new ImmediateAwareSqlitePdo($dsn, $username, $password, $options);
    }
}
