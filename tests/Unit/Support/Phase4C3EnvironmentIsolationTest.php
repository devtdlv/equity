<?php

namespace Tests\Unit\Support;

use App\Database\SQLite\ImmediateAwareSqlitePdo;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Support\Env;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Tests\Integration\Phase4C3\Phase4C3Isolation;
use Tests\Integration\SqliteFile\SqliteFileIsolation;

/**
 * Phase 4C.3 environment-redirect isolation.
 *
 * Uses synthetic fixtures under system temp only. Does not boot the real application
 * Kernel, does not open the repository `.env`, and does not connect to any database
 * unless an explicit connection-attempt probe is invoked after isolation succeeds.
 */
final class Phase4C3EnvironmentIsolationTest extends TestCase
{
    private string $tempRoot;

    private string $syntheticRepo;

    private string $syntheticProtectedEnv;

    private string $workdir;

    private string $token;

    private string $envFile;

    /** @var list<string> */
    private array $cleanupFiles = [];

    /** @var list<string> */
    private array $cleanupDirs = [];

    /** @var array<string, string|false|null> */
    private array $envRestore = [];

    protected function setUp(): void
    {
        parent::setUp();

        $temp = realpath(sys_get_temp_dir());
        $this->assertNotFalse($temp);
        $this->tempRoot = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $temp);

        $realRepo = realpath(dirname(__DIR__, 3));
        $this->assertNotFalse($realRepo);
        $realRepo = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $realRepo);
        $this->assertFalse(str_starts_with($this->tempRoot, $realRepo.DIRECTORY_SEPARATOR));

        $this->token = bin2hex(random_bytes(16));

        $this->syntheticRepo = $this->tempRoot.DIRECTORY_SEPARATOR.'equity-phase4c3-synth-repo-'.$this->token;
        $this->assertTrue(mkdir($this->syntheticRepo, 0700));
        $this->cleanupDirs[] = $this->syntheticRepo;

        // Synthetic protected .env — never the repository's real .env.
        $this->syntheticProtectedEnv = $this->syntheticRepo.DIRECTORY_SEPARATOR.'.env';
        $handle = fopen($this->syntheticProtectedEnv, 'x+b');
        $this->assertNotFalse($handle);
        fwrite($handle, "SYNTHETIC_SECRET=do-not-load\n");
        fclose($handle);
        $this->cleanupFiles[] = $this->syntheticProtectedEnv;

        $this->workdir = $this->tempRoot.DIRECTORY_SEPARATOR.'equity-phase4c3-work-'.$this->token;
        $this->assertTrue(mkdir($this->workdir, 0700));
        $this->cleanupDirs[] = $this->workdir;

        $this->envFile = $this->workdir.DIRECTORY_SEPARATOR.Phase4C3Isolation::environmentBasenameForToken($this->token);
        $envHandle = fopen($this->envFile, 'x+b');
        $this->assertNotFalse($envHandle);
        fwrite($envHandle, "DISPOSABLE_BASE=1\n");
        fclose($envHandle);
        $this->cleanupFiles[] = $this->envFile;
    }

    protected function tearDown(): void
    {
        $this->restoreProcessEnv();

        foreach ($this->cleanupFiles as $path) {
            if (is_link($path) || is_file($path)) {
                @unlink($path);
            }
        }
        foreach (array_reverse($this->cleanupDirs) as $dir) {
            if (is_dir($dir)) {
                foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $child) {
                    if (is_link($child) || is_file($child)) {
                        @unlink($child);
                    } elseif (is_dir($child)) {
                        foreach (glob($child.DIRECTORY_SEPARATOR.'*') ?: [] as $nested) {
                            if (is_link($nested) || is_file($nested)) {
                                @unlink($nested);
                            }
                        }
                        @rmdir($child);
                    }
                }
                @rmdir($dir);
            }
        }

        parent::tearDown();
    }

    public function test_successful_redirect_targets_disposable_file_not_protected_dotenv(): void
    {
        $result = Phase4C3Isolation::validateEnvironmentRedirect($this->snapshot());

        $this->assertTrue($result['ok'], implode("\n", $result['errors']));
        $this->assertSame(Phase4C3Isolation::environmentBasenameForToken($this->token), $result['environment_file']);
        $this->assertFalse($result['selected_via_app_env_suffix']);
        $this->assertNotNull($result['resolved_load_path']);
        $this->assertNotSame(
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) realpath($this->syntheticProtectedEnv)),
            $result['resolved_load_path'],
        );

        $app = new Application($this->syntheticRepo);
        Phase4C3Isolation::redirectEnvironmentAwayFromProtectedDotEnv($app, $this->snapshot());

        $this->assertSame(
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $this->workdir),
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $app->environmentPath()),
        );
        $this->assertSame(
            Phase4C3Isolation::environmentBasenameForToken($this->token),
            $app->environmentFile(),
        );
        $this->assertNotSame('.env', $app->environmentFile());
    }

    public function test_missing_environment_file_is_rejected(): void
    {
        @unlink($this->envFile);

        $result = Phase4C3Isolation::validateEnvironmentRedirect($this->snapshot());

        $this->assertFalse($result['ok']);
        $this->assertTrue($this->errorsContain($result['errors'], 'existing regular file'));
    }

    public function test_missing_workdir_is_rejected(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['workdir'] = $this->tempRoot.DIRECTORY_SEPARATOR.'missing-work-'.$this->token;

        $result = Phase4C3Isolation::validateEnvironmentRedirect($snapshot);

        $this->assertFalse($result['ok']);
        $this->assertTrue($this->errorsContain($result['errors'], 'existing directory'));
    }

    public function test_token_mismatch_on_environment_basename_is_rejected(): void
    {
        $other = bin2hex(random_bytes(16));
        $wrong = $this->workdir.DIRECTORY_SEPARATOR.Phase4C3Isolation::environmentBasenameForToken($other);
        $handle = fopen($wrong, 'x+b');
        $this->assertNotFalse($handle);
        fclose($handle);
        $this->cleanupFiles[] = $wrong;

        $snapshot = $this->snapshot();
        $snapshot['environment_file'] = $wrong;

        $result = Phase4C3Isolation::validateEnvironmentRedirect($snapshot);

        $this->assertFalse($result['ok']);
        $this->assertTrue($this->errorsContain($result['errors'], 'exactly'));
    }

    public function test_path_traversal_in_environment_file_is_rejected(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['environment_file'] = $this->workdir.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR
            .basename($this->workdir).DIRECTORY_SEPARATOR
            .Phase4C3Isolation::environmentBasenameForToken($this->token);

        $result = Phase4C3Isolation::validateEnvironmentRedirect($snapshot);

        $this->assertFalse($result['ok']);
        $this->assertTrue($this->errorsContain($result['errors'], 'path traversal'));
    }

    public function test_protected_dotenv_basename_is_rejected(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['environment_file'] = $this->syntheticProtectedEnv;

        $result = Phase4C3Isolation::validateEnvironmentRedirect($snapshot);

        $this->assertFalse($result['ok']);
        $this->assertTrue(
            $this->errorsContain($result['errors'], 'Protected .env basename')
            || $this->errorsContain($result['errors'], 'exactly'),
        );
    }

    public function test_environment_file_outside_workdir_is_rejected(): void
    {
        $outside = $this->tempRoot.DIRECTORY_SEPARATOR.Phase4C3Isolation::environmentBasenameForToken($this->token);
        $handle = fopen($outside, 'x+b');
        $this->assertNotFalse($handle);
        fclose($handle);
        $this->cleanupFiles[] = $outside;

        $snapshot = $this->snapshot();
        $snapshot['environment_file'] = $outside;

        $result = Phase4C3Isolation::validateEnvironmentRedirect($snapshot);

        $this->assertFalse($result['ok']);
        $this->assertTrue($this->errorsContain($result['errors'], 'owned workdir'));
    }

    public function test_workdir_inside_synthetic_repo_is_rejected(): void
    {
        $inside = $this->syntheticRepo.DIRECTORY_SEPARATOR.'equity-phase4c3-work-'.$this->token;
        $this->assertTrue(mkdir($inside, 0700));
        $this->cleanupDirs[] = $inside;
        $envInside = $inside.DIRECTORY_SEPARATOR.Phase4C3Isolation::environmentBasenameForToken($this->token);
        $handle = fopen($envInside, 'x+b');
        $this->assertNotFalse($handle);
        fclose($handle);
        $this->cleanupFiles[] = $envInside;

        $snapshot = $this->snapshot();
        $snapshot['workdir'] = $inside;
        $snapshot['environment_file'] = $envInside;

        $result = Phase4C3Isolation::validateEnvironmentRedirect($snapshot);

        $this->assertFalse($result['ok']);
        $this->assertTrue($this->errorsContain($result['errors'], 'inside the repository'));
    }

    public function test_app_env_suffix_selection_is_accepted_when_owned(): void
    {
        $suffix = $this->envFile.'.testing';
        $handle = fopen($suffix, 'x+b');
        $this->assertNotFalse($handle);
        fwrite($handle, "DISPOSABLE_ONLY=1\n");
        fclose($handle);
        $this->cleanupFiles[] = $suffix;

        $result = Phase4C3Isolation::validateEnvironmentRedirect($this->snapshot(['app_env' => 'testing']));

        $this->assertTrue($result['ok'], implode("\n", $result['errors']));
        $this->assertTrue($result['selected_via_app_env_suffix']);
        $this->assertSame(Phase4C3Isolation::environmentBasenameForToken($this->token), $result['environment_file']);
        $this->assertSame(
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) realpath($suffix)),
            $result['resolved_load_path'],
        );

        $app = new Application($this->syntheticRepo);
        Phase4C3Isolation::redirectEnvironmentAwayFromProtectedDotEnv($app, $this->snapshot(['app_env' => 'testing']));
        $this->assertSame(Phase4C3Isolation::environmentBasenameForToken($this->token), $app->environmentFile());
    }

    public function test_redirect_throws_when_configuration_invalid(): void
    {
        $app = new Application($this->syntheticRepo);
        $snapshot = $this->snapshot();
        $snapshot['environment_file'] = null;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refused to redirect');
        Phase4C3Isolation::redirectEnvironmentAwayFromProtectedDotEnv($app, $snapshot);
    }

    public function test_resolved_load_path_never_uses_dotenv_basename(): void
    {
        $result = Phase4C3Isolation::validateEnvironmentRedirect($this->snapshot());

        $this->assertTrue($result['ok'], implode("\n", $result['errors']));
        $this->assertNotNull($result['resolved_load_path']);
        $this->assertNotSame('.env', basename($result['resolved_load_path']));
        $this->assertFalse(str_starts_with(
            $result['resolved_load_path'],
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $this->syntheticRepo).DIRECTORY_SEPARATOR,
        ));
    }

    public function test_app_env_value_with_path_traversal_is_rejected(): void
    {
        $result = Phase4C3Isolation::validateEnvironmentRedirect($this->snapshot([
            'app_env' => '..'.DIRECTORY_SEPARATOR.'.env',
        ]));

        $this->assertFalse($result['ok']);
        $this->assertTrue($this->errorsContain($result['errors'], 'path separators or traversal'));
        $this->assertFalse($result['selected_via_app_env_suffix']);
    }

    public function test_app_env_suffix_hardlink_to_protected_dotenv_is_rejected(): void
    {
        $suffix = $this->envFile.'.testing';
        if (! @link($this->syntheticProtectedEnv, $suffix)) {
            $this->markTestSkipped('Hardlink creation is unavailable in this environment.');
        }
        $this->cleanupFiles[] = $suffix;

        $result = Phase4C3Isolation::validateEnvironmentRedirect($this->snapshot(['app_env' => 'testing']));

        $this->assertFalse($result['ok']);
        $this->assertTrue($this->errorsContain($result['errors'], 'collides with the protected'));
        $this->assertFalse($result['selected_via_app_env_suffix']);
    }

    public function test_app_env_suffix_symlink_to_protected_dotenv_is_rejected(): void
    {
        $suffix = $this->envFile.'.testing';
        if (! $this->trySymlink($this->syntheticProtectedEnv, $suffix)) {
            $this->markTestSkipped('Symlink creation is unavailable in this environment.');
        }
        $this->cleanupFiles[] = $suffix;

        $result = Phase4C3Isolation::validateEnvironmentRedirect($this->snapshot(['app_env' => 'testing']));

        $this->assertFalse($result['ok']);
        $this->assertTrue($this->errorsContain($result['errors'], 'must not be a symlink'));
        $this->assertFalse($result['selected_via_app_env_suffix']);
    }

    public function test_app_env_suffix_symlink_escaping_workdir_is_rejected(): void
    {
        $outside = $this->tempRoot.DIRECTORY_SEPARATOR.'equity-phase4c3-hostile-env-'.$this->token;
        $handle = fopen($outside, 'x+b');
        $this->assertNotFalse($handle);
        fwrite($handle, "HOSTILE=1\n");
        fclose($handle);
        $this->cleanupFiles[] = $outside;

        $suffix = $this->envFile.'.testing';
        if (! $this->trySymlink($outside, $suffix)) {
            $this->markTestSkipped('Symlink creation is unavailable in this environment.');
        }
        $this->cleanupFiles[] = $suffix;

        $result = Phase4C3Isolation::validateEnvironmentRedirect($this->snapshot(['app_env' => 'testing']));

        $this->assertFalse($result['ok']);
        $this->assertTrue($this->errorsContain($result['errors'], 'must not be a symlink'));
    }

    public function test_laravel_load_environment_variables_selects_owned_app_env_suffix(): void
    {
        $suffix = $this->envFile.'.testing';
        $handle = fopen($suffix, 'x+b');
        $this->assertNotFalse($handle);
        fwrite($handle, "DISPOSABLE_SUFFIX=1\n");
        fclose($handle);
        $this->cleanupFiles[] = $suffix;

        $app = new Application($this->syntheticRepo);
        Phase4C3Isolation::redirectEnvironmentAwayFromProtectedDotEnv($app, $this->snapshot(['app_env' => 'testing']));

        $this->forceProcessEnv(['APP_ENV' => 'testing']);
        Env::getRepository()->set('APP_ENV', 'testing');

        $this->invokeLaravelSpecificEnvironmentFileSelection($app);

        $expected = Phase4C3Isolation::environmentBasenameForToken($this->token).'.testing';
        $this->assertSame($expected, $app->environmentFile());
        $resolved = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $app->environmentFilePath());
        $this->assertSame(
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) realpath($suffix)),
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) realpath($resolved)),
        );
        $this->assertNotSame(
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) realpath($this->syntheticProtectedEnv)),
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) realpath($resolved)),
        );
    }

    public function test_laravel_does_not_select_synthetic_repo_dotenv_testing_after_redirect(): void
    {
        $repoTesting = $this->syntheticRepo.DIRECTORY_SEPARATOR.'.env.testing';
        $handle = fopen($repoTesting, 'x+b');
        $this->assertNotFalse($handle);
        fwrite($handle, "HOSTILE_REPO_SUFFIX=1\n");
        fclose($handle);
        $this->cleanupFiles[] = $repoTesting;

        $app = new Application($this->syntheticRepo);
        Phase4C3Isolation::redirectEnvironmentAwayFromProtectedDotEnv($app, $this->snapshot(['app_env' => 'testing']));

        $this->forceProcessEnv(['APP_ENV' => 'testing']);
        Env::getRepository()->set('APP_ENV', 'testing');

        $this->invokeLaravelSpecificEnvironmentFileSelection($app);

        // No owned workdir suffix → Laravel keeps the redirected base basename (not repo .env.testing).
        $this->assertSame(Phase4C3Isolation::environmentBasenameForToken($this->token), $app->environmentFile());
        $this->assertNotSame('.env.testing', $app->environmentFile());
        $this->assertNotSame('.env', $app->environmentFile());
        $resolved = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) realpath($app->environmentFilePath()));
        $this->assertSame(
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) realpath($this->envFile)),
            $resolved,
        );
    }

    public function test_invalid_redirect_fails_closed_before_connection_probe(): void
    {
        $dbPath = $this->createDisposableSqliteFile();
        $probe = $this->newConnectionAttemptProbe($dbPath);
        ImmediateAwareSqlitePdo::resetInstancesConstructed();
        $pdoBefore = ImmediateAwareSqlitePdo::instancesConstructed();

        $app = new Application($this->syntheticRepo);
        $snapshot = $this->snapshot();
        $snapshot['environment_file'] = null;

        try {
            Phase4C3Isolation::redirectEnvironmentAwayFromProtectedDotEnv($app, $snapshot);
            // Mirrored artisan order: connect only after redirect succeeds.
            $probe->connect();
            $this->fail('Expected redirect to refuse invalid isolation configuration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('refused to redirect', $exception->getMessage());
        }

        $this->assertSame(0, $probe->attempts);
        $this->assertSame($pdoBefore, ImmediateAwareSqlitePdo::instancesConstructed());
        $this->assertSame(0, $probe->pdoInstancesOpened);
    }

    public function test_boot_from_environment_fails_closed_when_env_file_missing_with_disposable_db_present(): void
    {
        $dbPath = $this->createDisposableSqliteFile();
        $probe = $this->newConnectionAttemptProbe($dbPath);
        ImmediateAwareSqlitePdo::resetInstancesConstructed();
        $pdoBefore = ImmediateAwareSqlitePdo::instancesConstructed();

        $dbDir = $this->syntheticRepo.DIRECTORY_SEPARATOR.'database';
        $this->assertTrue(mkdir($dbDir, 0700));
        $this->cleanupDirs[] = $dbDir;
        $forbidden = $dbDir.DIRECTORY_SEPARATOR.'database.sqlite';

        $this->forceProcessEnv([
            Phase4C3Isolation::ENV_TOKEN => $this->token,
            Phase4C3Isolation::ENV_SQLITE_PATH => $dbPath,
            Phase4C3Isolation::ENV_REPO_ROOT => $this->syntheticRepo,
            Phase4C3Isolation::ENV_FORBIDDEN_DB => $forbidden,
            Phase4C3Isolation::ENV_WORKDIR => $this->workdir,
            Phase4C3Isolation::ENV_ENV_FILE => '',
            'DB_DATABASE' => $dbPath,
            'APP_ENV' => 'testing',
        ]);

        try {
            Phase4C3Isolation::bootFromEnvironment($this->syntheticRepo);
            $probe->connect();
            $this->fail('Expected bootFromEnvironment to refuse missing disposable environment file.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('refused to boot', $exception->getMessage());
            $this->assertTrue(
                str_contains($exception->getMessage(), 'environment file')
                || str_contains($exception->getMessage(), 'ENV_FILE')
                || str_contains($exception->getMessage(), 'Environment redirect'),
            );
        }

        $this->assertSame(0, $probe->attempts);
        $this->assertSame($pdoBefore, ImmediateAwareSqlitePdo::instancesConstructed());
        $this->assertSame(0, $probe->pdoInstancesOpened);
    }

    public function test_connection_attempt_probe_detects_explicit_open(): void
    {
        $dbPath = $this->createDisposableSqliteFile();
        $probe = $this->newConnectionAttemptProbe($dbPath);

        $pdo = $probe->connect();
        $this->assertInstanceOf(PDO::class, $pdo);
        $this->assertSame(1, $probe->attempts);
        $this->assertSame(1, $probe->pdoInstancesOpened);
        $pdo = null;
        gc_collect_cycles();
    }

    public function test_unsafe_app_env_suffix_blocks_redirect_before_connection_probe(): void
    {
        $dbPath = $this->createDisposableSqliteFile();
        $probe = $this->newConnectionAttemptProbe($dbPath);

        $suffix = $this->envFile.'.testing';
        $linked = @link($this->syntheticProtectedEnv, $suffix) || $this->trySymlink($this->syntheticProtectedEnv, $suffix);
        if (! $linked) {
            $this->markTestSkipped('Neither hardlink nor symlink creation is available in this environment.');
        }
        $this->cleanupFiles[] = $suffix;

        $app = new Application($this->syntheticRepo);

        try {
            Phase4C3Isolation::redirectEnvironmentAwayFromProtectedDotEnv(
                $app,
                $this->snapshot(['app_env' => 'testing']),
            );
            $probe->connect();
            $this->fail('Expected unsafe APP_ENV suffix to refuse redirect.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('refused to redirect', $exception->getMessage());
            $this->assertTrue(
                str_contains($exception->getMessage(), 'symlink')
                || str_contains($exception->getMessage(), 'collides with the protected'),
            );
        }

        $this->assertSame(0, $probe->attempts);
    }

    /**
     * @param  array<string, string|null>  $overrides
     * @return array<string, string|null>
     */
    private function snapshot(array $overrides = []): array
    {
        return array_merge([
            'token' => $this->token,
            'repository_root' => $this->syntheticRepo,
            'workdir' => $this->workdir,
            'environment_file' => $this->envFile,
            'app_env' => 'testing',
            'protected_dotenv' => $this->syntheticProtectedEnv,
        ], $overrides);
    }

    /**
     * @param  list<string>  $errors
     */
    private function errorsContain(array $errors, string $needle): bool
    {
        foreach ($errors as $error) {
            if (str_contains($error, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function createDisposableSqliteFile(): string
    {
        $path = $this->tempRoot.DIRECTORY_SEPARATOR.Phase4C3Isolation::databaseBasenameForToken($this->token);
        $handle = fopen($path, 'x+b');
        $this->assertNotFalse($handle);
        fclose($handle);
        $this->cleanupFiles[] = $path;
        $this->assertNotSame(
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) realpath($this->syntheticProtectedEnv)),
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) realpath($path)),
        );

        return $path;
    }

    /**
     * Explicit connection-attempt detector (not filesystem mtime).
     *
     * @return object{attempts: int, pdoInstancesOpened: int, connect: callable(): PDO}
     */
    private function newConnectionAttemptProbe(string $sqlitePath): object
    {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) realpath($sqlitePath));
        $this->assertNotFalse($normalized);

        return new class($normalized)
        {
            public int $attempts = 0;

            public int $pdoInstancesOpened = 0;

            public function __construct(private string $path) {}

            public function connect(): PDO
            {
                $this->attempts++;
                $pdo = new PDO('sqlite:'.$this->path, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
                $this->pdoInstancesOpened++;

                return $pdo;
            }
        };
    }

    private function invokeLaravelSpecificEnvironmentFileSelection(Application $app): void
    {
        $bootstrapper = new LoadEnvironmentVariables;
        $method = new ReflectionMethod(LoadEnvironmentVariables::class, 'checkForSpecificEnvironmentFile');
        $method->invoke($bootstrapper, $app);
    }

    private function trySymlink(string $target, string $link): bool
    {
        if (file_exists($link) || is_link($link)) {
            return false;
        }

        return @symlink($target, $link);
    }

    /**
     * @param  array<string, string>  $values
     */
    private function forceProcessEnv(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, $this->envRestore)) {
                $current = getenv($key);
                $this->envRestore[$key] = $current === false ? false : (string) $current;
            }
        }
        SqliteFileIsolation::forceEnv($values);
    }

    private function restoreProcessEnv(): void
    {
        foreach ($this->envRestore as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv($key.'='.$value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
        $this->envRestore = [];
    }
}
