<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tests\Integration\SqliteFile\SqliteFileIsolation;

/**
 * Negative isolation checks for Phase 2. Does not boot Laravel or open the real database.
 */
final class SqliteFileIsolationSafetyTest extends TestCase
{
    private string $repoRoot;

    private string $forbiddenDb;

    private string $tempDir;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $root = realpath(dirname(__DIR__, 3));
        $this->assertNotFalse($root);
        $this->repoRoot = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root);
        $this->forbiddenDb = $this->repoRoot.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite';

        $temp = realpath(sys_get_temp_dir());
        $this->assertNotFalse($temp);
        $this->tempDir = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $temp);
        $this->assertFalse(str_starts_with($this->tempDir, $this->repoRoot.DIRECTORY_SEPARATOR));
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path) && ! is_link($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_missing_token_is_rejected(): void
    {
        $token = bin2hex(random_bytes(16));
        $db = $this->createDisposableFile($token);

        $result = SqliteFileIsolation::validate([
            SqliteFileIsolation::ENV_TOKEN => null,
            SqliteFileIsolation::ENV_SQLITE_PATH => $db,
            SqliteFileIsolation::ENV_REPO_ROOT => $this->repoRoot,
            SqliteFileIsolation::ENV_FORBIDDEN_DB => $this->forbiddenDb,
            'DB_DATABASE' => $db,
        ], $this->repoRoot);

        $this->assertFalse($result['ok']);
        $this->assertTrue($this->errorsContain($result['errors'], 'token is missing'));
    }

    public function test_malformed_token_is_rejected(): void
    {
        $token = bin2hex(random_bytes(16));
        $db = $this->createDisposableFile($token);

        $result = SqliteFileIsolation::validate([
            SqliteFileIsolation::ENV_TOKEN => 'not-a-valid-token',
            SqliteFileIsolation::ENV_SQLITE_PATH => $db,
            SqliteFileIsolation::ENV_REPO_ROOT => $this->repoRoot,
            SqliteFileIsolation::ENV_FORBIDDEN_DB => $this->forbiddenDb,
            'DB_DATABASE' => $db,
        ], $this->repoRoot);

        $this->assertFalse($result['ok']);
        $this->assertTrue($this->errorsContain($result['errors'], 'hexadecimal'));
    }

    public function test_uppercase_token_is_rejected(): void
    {
        $token = strtoupper(bin2hex(random_bytes(16)));
        $dbPath = $this->tempDir.DIRECTORY_SEPARATOR.'equity-phase2-'.strtolower($token).'.sqlite';
        // Basename must match lowercase token form; create with lowercase name and claim uppercase token.
        $handle = fopen($dbPath, 'x+b');
        $this->assertNotFalse($handle);
        fclose($handle);
        $this->tempFiles[] = $dbPath;

        $result = SqliteFileIsolation::validate([
            SqliteFileIsolation::ENV_TOKEN => $token,
            SqliteFileIsolation::ENV_SQLITE_PATH => $dbPath,
            SqliteFileIsolation::ENV_REPO_ROOT => $this->repoRoot,
            SqliteFileIsolation::ENV_FORBIDDEN_DB => $this->forbiddenDb,
            'DB_DATABASE' => $dbPath,
        ], $this->repoRoot);

        $this->assertFalse($result['ok']);
        $this->assertTrue($this->errorsContain($result['errors'], 'hexadecimal'));
    }

    public function test_mismatched_database_filename_is_rejected(): void
    {
        $token = bin2hex(random_bytes(16));
        $wrong = $this->tempDir.DIRECTORY_SEPARATOR.'equity-phase2-wrong-'.$token.'.sqlite';
        $handle = fopen($wrong, 'x+b');
        $this->assertNotFalse($handle);
        fclose($handle);
        $this->tempFiles[] = $wrong;

        $result = SqliteFileIsolation::validate([
            SqliteFileIsolation::ENV_TOKEN => $token,
            SqliteFileIsolation::ENV_SQLITE_PATH => $wrong,
            SqliteFileIsolation::ENV_REPO_ROOT => $this->repoRoot,
            SqliteFileIsolation::ENV_FORBIDDEN_DB => $this->forbiddenDb,
            'DB_DATABASE' => $wrong,
        ], $this->repoRoot);

        $this->assertFalse($result['ok']);
        $this->assertTrue($this->errorsContain($result['errors'], 'basename must be exactly'));
    }

    public function test_wrong_repository_root_is_rejected(): void
    {
        $token = bin2hex(random_bytes(16));
        $db = $this->createDisposableFile($token);

        $result = SqliteFileIsolation::validate([
            SqliteFileIsolation::ENV_TOKEN => $token,
            SqliteFileIsolation::ENV_SQLITE_PATH => $db,
            SqliteFileIsolation::ENV_REPO_ROOT => $this->tempDir,
            SqliteFileIsolation::ENV_FORBIDDEN_DB => $this->forbiddenDb,
            'DB_DATABASE' => $db,
        ], $this->repoRoot);

        $this->assertFalse($result['ok']);
        $this->assertTrue($this->errorsContain($result['errors'], 'does not match the bootstrap-derived project root'));
    }

    public function test_wrong_forbidden_path_is_rejected(): void
    {
        $token = bin2hex(random_bytes(16));
        $db = $this->createDisposableFile($token);

        $result = SqliteFileIsolation::validate([
            SqliteFileIsolation::ENV_TOKEN => $token,
            SqliteFileIsolation::ENV_SQLITE_PATH => $db,
            SqliteFileIsolation::ENV_REPO_ROOT => $this->repoRoot,
            SqliteFileIsolation::ENV_FORBIDDEN_DB => $this->tempDir.DIRECTORY_SEPARATOR.'not-the-app-database.sqlite',
            'DB_DATABASE' => $db,
        ], $this->repoRoot);

        $this->assertFalse($result['ok']);
        $this->assertTrue($this->errorsContain($result['errors'], 'database/database.sqlite'));
    }

    public function test_db_database_mismatch_is_rejected(): void
    {
        $token = bin2hex(random_bytes(16));
        $db = $this->createDisposableFile($token);
        $otherToken = bin2hex(random_bytes(16));
        $other = $this->createDisposableFile($otherToken);

        $result = SqliteFileIsolation::validate([
            SqliteFileIsolation::ENV_TOKEN => $token,
            SqliteFileIsolation::ENV_SQLITE_PATH => $db,
            SqliteFileIsolation::ENV_REPO_ROOT => $this->repoRoot,
            SqliteFileIsolation::ENV_FORBIDDEN_DB => $this->forbiddenDb,
            'DB_DATABASE' => $other,
        ], $this->repoRoot);

        $this->assertFalse($result['ok']);
        $this->assertTrue($this->errorsContain($result['errors'], 'DB_DATABASE does not match'));
    }

    public function test_valid_snapshot_is_accepted_without_opening_application_database(): void
    {
        $token = bin2hex(random_bytes(16));
        $db = $this->createDisposableFile($token);

        // Ensure we never confuse the disposable file with the real app DB path string.
        $this->assertNotSame($db, $this->forbiddenDb);

        $result = SqliteFileIsolation::validate([
            SqliteFileIsolation::ENV_TOKEN => $token,
            SqliteFileIsolation::ENV_SQLITE_PATH => $db,
            SqliteFileIsolation::ENV_REPO_ROOT => $this->repoRoot,
            SqliteFileIsolation::ENV_FORBIDDEN_DB => $this->forbiddenDb,
            'DB_DATABASE' => $db,
        ], $this->repoRoot);

        $this->assertTrue($result['ok'], implode("\n", $result['errors']));
        $this->assertSame($token, $result['token']);
        $this->assertSame($db, $result['approved_database_path']);
        $this->assertSame($this->repoRoot, $result['repository_root']);
    }

    public function test_unsafe_direct_phpunit_phase2_execution_fails_closed(): void
    {
        $php = PHP_BINARY;
        $phpunit = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'phpunit'.DIRECTORY_SEPARATOR.'phpunit'.DIRECTORY_SEPARATOR.'phpunit';
        $config = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'phpunit.phase2-sqlite-file.xml';
        $this->assertFileExists($phpunit);
        $this->assertFileExists($config);

        $command = [
            $php,
            $phpunit,
            '--configuration',
            $config,
            '--filter',
            'this_filter_matches_nothing_phase2_safety',
            '--colors=never',
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Intentionally omit EQUITY_PHASE2_* so bootstrap must refuse before Laravel DB use.
        $env = [];
        foreach (['PATH', 'PATHEXT', 'SystemRoot', 'SystemDrive', 'COMSPEC', 'TEMP', 'TMP', 'HOME', 'USERPROFILE'] as $key) {
            $value = getenv($key);
            if ($value !== false && $value !== '') {
                $env[$key] = $value;
            }
        }
        $env['APP_ENV'] = 'testing';
        $env['DB_CONNECTION'] = 'sqlite';
        // Hostile inherited pointer at the real app DB name string — bootstrap must still refuse.
        $env['DB_DATABASE'] = $this->forbiddenDb;

        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes, $this->repoRoot, $env);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        $output = $stdout.$stderr;
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('Phase 2', $output);
        $this->assertStringNotContainsString('OK (', $output);
    }

    private function createDisposableFile(string $token): string
    {
        $path = $this->tempDir.DIRECTORY_SEPARATOR.SqliteFileIsolation::databaseBasenameForToken($token);
        $handle = fopen($path, 'x+b');
        $this->assertNotFalse($handle, 'exclusive create failed for disposable safety fixture');
        fclose($handle);
        $this->tempFiles[] = $path;

        $real = realpath($path);
        $this->assertNotFalse($real);

        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $real);
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
}
