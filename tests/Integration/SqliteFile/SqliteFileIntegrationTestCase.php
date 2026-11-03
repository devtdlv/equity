<?php

namespace Tests\Integration\SqliteFile;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;
use Tests\Support\SqliteFileConnectionGuard;

/**
 * Laravel test case for Phase 2 file-based SQLite integration only.
 * Intentionally does not extend Tests\TestCase (which enforces :memory:).
 */
abstract class SqliteFileIntegrationTestCase extends BaseTestCase
{
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $this->assertSqliteFileConnection();
    }

    /**
     * @return array<class-string, int>
     */
    protected function setUpTraits()
    {
        $this->assertSqliteFileConnection();

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function assertSqliteFileConnection(): void
    {
        if ($this->app === null) {
            throw new RuntimeException('Phase 2 SQLite file guard failed. Test application is not available.');
        }

        $default = $this->app['config']->get('database.default');
        $result = SqliteFileConnectionGuard::evaluate([
            'default' => $default,
            'connections' => $this->app['config']->get('database.connections'),
            'approved_database_path' => SqliteFileIsolation::approvedDatabasePath(),
            'repository_root' => SqliteFileIsolation::repositoryRoot(),
            'forbidden_database_path' => SqliteFileIsolation::forbiddenDatabasePath(),
            'run_token' => SqliteFileIsolation::runToken(),
            'expected_database_basename' => SqliteFileIsolation::databaseBasenameForToken(SqliteFileIsolation::runToken()),
            'transacting_connections' => is_string($default) ? [$default] : $default,
            'config_loaded_from_cache' => $this->app->configurationIsCached(),
            'resolved_config_cache_exists' => is_file($this->app->getCachedConfigPath()),
            'default_config_cache_exists' => is_file($this->app->bootstrapPath('cache/config.php')),
        ]);

        if ($result['ok'] !== true) {
            throw new RuntimeException(SqliteFileConnectionGuard::failureMessage($result));
        }
    }
}
