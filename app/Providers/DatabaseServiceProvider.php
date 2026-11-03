<?php

namespace App\Providers;

use App\Database\SQLite\EquitySQLiteConnection;
use App\Database\SQLite\EquitySQLiteConnector;
use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Registers Equity SQLite IMMEDIATE infrastructure.
 *
 * Connector + connection subclass are registered for production. BEGIN IMMEDIATE remains
 * gated by config equity.sqlite.financial_immediate (default false).
 *
 * An existing Connection::resolverFor('sqlite') is never overwritten (Phase 3 test-only
 * IMMEDIATE workers register before bootstrap). If financial_immediate is enabled while
 * a non-Equity resolver owns sqlite, boot fails closed.
 */
final class DatabaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('db.connector.sqlite', function () {
            return new EquitySQLiteConnector;
        });

        $existing = Connection::getResolver('sqlite');

        if ($existing === null) {
            Connection::resolverFor('sqlite', function ($connection, $database, $prefix, $config) {
                return new EquitySQLiteConnection($connection, $database, $prefix, $config);
            });

            return;
        }

        // Do not replace an existing resolver (unknown owner / Phase 3 trial).
        // Fail closed only when production IMMEDIATE is enabled under a foreign resolver.
        $this->app->booting(function (): void {
            if (! (bool) config('equity.sqlite.financial_immediate', false)) {
                return;
            }

            // A pre-existing resolver means DatabaseServiceProvider did not install Equity's.
            throw new RuntimeException(
                'equity.sqlite.financial_immediate is enabled but a sqlite Connection '
                .'resolver was already registered before DatabaseServiceProvider. Refusing to boot. '
                .'Disable the flag or remove the foreign resolver. Existing resolver is not overwritten.',
            );
        });
    }
}
