<?php

namespace Tests\Integration\Phase4C3;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;
use Tests\Support\SqliteFileConnectionGuard;

/**
 * Laravel test case for Phase 4C.3 multi-process SQLite concurrency tests.
 * Does not extend Tests\TestCase (:memory: guard).
 */
abstract class Phase4C3IntegrationTestCase extends BaseTestCase
{
    /**
     * Boot the application only after redirecting Dotenv away from the repository `.env`.
     */
    public function createApplication()
    {
        /** @var Application $app */
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        Phase4C3Isolation::redirectEnvironmentAwayFromProtectedDotEnv($app);

        $envFile = basename(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $app->environmentFilePath()));
        if ($envFile === '.env' || strcasecmp($envFile, '.env') === 0) {
            throw new RuntimeException('Phase 4C.3 refused to bootstrap against a protected .env basename.');
        }

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $this->assertPhase4C3SqliteFileConnection();
    }

    /**
     * @return array<class-string, int>
     */
    protected function setUpTraits()
    {
        $this->assertPhase4C3SqliteFileConnection();

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        // Default remains off; individual scenarios enable IMMEDIATE only for disposable workers.
        config(['equity.sqlite.financial_immediate' => false]);
    }

    protected function assertPhase4C3SqliteFileConnection(): void
    {
        if ($this->app === null) {
            throw new RuntimeException('Phase 4C.3 SQLite file guard failed. Test application is not available.');
        }

        $default = $this->app['config']->get('database.default');
        $result = SqliteFileConnectionGuard::evaluate([
            'default' => $default,
            'connections' => $this->app['config']->get('database.connections'),
            'approved_database_path' => Phase4C3Isolation::approvedDatabasePath(),
            'repository_root' => Phase4C3Isolation::repositoryRoot(),
            'forbidden_database_path' => Phase4C3Isolation::forbiddenDatabasePath(),
            'run_token' => Phase4C3Isolation::runToken(),
            'expected_database_basename' => Phase4C3Isolation::databaseBasenameForToken(Phase4C3Isolation::runToken()),
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
