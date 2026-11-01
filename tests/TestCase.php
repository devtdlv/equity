<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;
use Tests\Support\SqliteMemoryConnectionGuard;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create the application, then refuse unsafe resolved database configuration
     * before RefreshDatabase, other database traits, or HTTP requests can run.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $this->assertSqliteMemoryConnection();
    }

    /**
     * Re-check immediately before Laravel boots RefreshDatabase, migrations,
     * truncation, or transactions.
     *
     * @return array<class-string, int>
     */
    protected function setUpTraits()
    {
        $this->assertSqliteMemoryConnection();

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * Inspect resolved config only. Does not open a database connection.
     */
    private function assertSqliteMemoryConnection(): void
    {
        if ($this->app === null) {
            throw new RuntimeException('Equity SQLite :memory: test guard failed. Test application is not available.');
        }

        $default = $this->app['config']->get('database.default');
        $result = SqliteMemoryConnectionGuard::evaluate([
            'default' => $default,
            'connections' => $this->app['config']->get('database.connections'),
            'transacting_connections' => $this->sqliteMemoryGuardTransactingConnections($default),
            'config_loaded_from_cache' => $this->app->configurationIsCached(),
            'resolved_config_cache_exists' => is_file($this->app->getCachedConfigPath()),
            'default_config_cache_exists' => is_file($this->app->bootstrapPath('cache/config.php')),
        ]);

        if ($result['ok'] !== true) {
            throw new RuntimeException(SqliteMemoryConnectionGuard::failureMessage($result));
        }
    }

    /**
     * Use Laravel's connectionsToTransact() method when present, including test overrides.
     * The default RefreshDatabase implementation only reads a property or config().
     *
     * @return list<mixed>|mixed
     */
    private function sqliteMemoryGuardTransactingConnections(mixed $default): mixed
    {
        if (! method_exists($this, 'connectionsToTransact')) {
            return is_string($default) ? [$default] : $default;
        }

        try {
            return $this->connectionsToTransact();
        } catch (\Throwable) {
            throw new RuntimeException('Equity SQLite :memory: test guard failed. Transaction connections could not be determined safely.');
        }
    }
}
