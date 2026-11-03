<?php

namespace Tests\Integration\Concurrency;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;
use Tests\Support\SqliteFileConnectionGuard;

/**
 * Laravel test case for Phase 3 multi-process SQLite concurrency tests.
 * Does not extend Tests\TestCase (:memory: guard).
 */
abstract class Phase3IntegrationTestCase extends BaseTestCase
{
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $this->assertPhase3SqliteFileConnection();
    }

    /**
     * @return array<class-string, int>
     */
    protected function setUpTraits()
    {
        $this->assertPhase3SqliteFileConnection();

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    protected function assertPhase3SqliteFileConnection(): void
    {
        if ($this->app === null) {
            throw new RuntimeException('Phase 3 SQLite file guard failed. Test application is not available.');
        }

        $default = $this->app['config']->get('database.default');
        $result = SqliteFileConnectionGuard::evaluate([
            'default' => $default,
            'connections' => $this->app['config']->get('database.connections'),
            'approved_database_path' => Phase3Isolation::approvedDatabasePath(),
            'repository_root' => Phase3Isolation::repositoryRoot(),
            'forbidden_database_path' => Phase3Isolation::forbiddenDatabasePath(),
            'run_token' => Phase3Isolation::runToken(),
            'expected_database_basename' => Phase3Isolation::databaseBasenameForToken(Phase3Isolation::runToken()),
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
