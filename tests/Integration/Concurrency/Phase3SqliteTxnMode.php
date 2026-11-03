<?php

namespace Tests\Integration\Concurrency;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Phase 3 worker-only SQLite transaction-mode activation.
 *
 * Default remains Laravel DEFERRED (PDO beginTransaction). IMMEDIATE is opt-in via
 * EQUITY_PHASE3_SQLITE_TXN_MODE=immediate and Connection::resolverFor('sqlite', …)
 * registered before Laravel instantiates a SQLite connection.
 */
final class Phase3SqliteTxnMode
{
    public const ENV = 'EQUITY_PHASE3_SQLITE_TXN_MODE';

    public const MODE_DEFERRED = 'deferred';

    public const MODE_IMMEDIATE = 'immediate';

    private static bool $immediateResolverRegistered = false;

    public static function requestedModeFromEnvironment(): string
    {
        $raw = $_SERVER[self::ENV] ?? $_ENV[self::ENV] ?? getenv(self::ENV);
        if ($raw === false || $raw === null || $raw === '') {
            return self::MODE_DEFERRED;
        }
        if (! is_string($raw)) {
            throw new RuntimeException(self::ENV.' must be a string when set.');
        }

        $mode = strtolower(trim($raw));
        if (! in_array($mode, [self::MODE_DEFERRED, self::MODE_IMMEDIATE], true)) {
            throw new RuntimeException(
                self::ENV.' must be "deferred" or "immediate"; got: '.$raw,
            );
        }

        return $mode;
    }

    public static function wantsImmediate(): bool
    {
        return self::requestedModeFromEnvironment() === self::MODE_IMMEDIATE;
    }

    /**
     * Must run after autoload and before Laravel creates any SQLite Connection.
     */
    public static function registerImmediateResolverIfRequested(): void
    {
        if (! self::wantsImmediate()) {
            return;
        }

        if (self::$immediateResolverRegistered) {
            return;
        }

        $existing = Connection::getResolver('sqlite');
        if ($existing !== null) {
            throw new RuntimeException(
                'IMMEDIATE SQLite mode requested but a sqlite Connection resolver is already registered; refusing to replace it.',
            );
        }

        Connection::resolverFor('sqlite', static function ($connection, $database, $prefix, $config) {
            // Open an IMMEDIATE-aware PDO for this disposable DB. Do not reuse the
            // connector's PDO instance: its beginTransaction() is always DEFERRED on PHP 8.2.
            $pdo = Phase3ImmediateSqlitePdo::forDatabase((string) $database, is_array($config) ? $config : []);

            return new Phase3ImmediateSQLiteConnection($pdo, $database, $prefix, $config);
        });

        self::$immediateResolverRegistered = true;
        Phase3ImmediateSQLiteConnection::resetBeginMetrics();
    }

    /**
     * Fail closed after bootstrap if IMMEDIATE was requested but not active,
     * or if the IMMEDIATE subclass leaked into a deferred worker.
     */
    public static function assertConnectionMatchesRequestedMode(Connection $connection): void
    {
        $mode = self::requestedModeFromEnvironment();

        if ($mode === self::MODE_IMMEDIATE) {
            if (! self::$immediateResolverRegistered) {
                throw new RuntimeException(
                    'IMMEDIATE mode requested but the Phase 3 SQLite resolver was not registered before boot.',
                );
            }
            if (! $connection instanceof Phase3ImmediateSQLiteConnection) {
                throw new RuntimeException(
                    'IMMEDIATE mode requested but SQLite connection is '.$connection::class
                    .' (expected Phase3ImmediateSQLiteConnection).',
                );
            }

            return;
        }

        if ($connection instanceof Phase3ImmediateSQLiteConnection) {
            throw new RuntimeException(
                'Phase3ImmediateSQLiteConnection must not be active unless '
                .self::ENV.'=immediate.',
            );
        }
    }

    public static function immediateResolverWasRegistered(): bool
    {
        return self::$immediateResolverRegistered;
    }
}
