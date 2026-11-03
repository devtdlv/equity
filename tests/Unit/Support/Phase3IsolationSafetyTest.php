<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tests\Integration\Concurrency\Phase3Isolation;

/**
 * Negative isolation checks for Phase 3. Does not boot Laravel or open the real database.
 */
final class Phase3IsolationSafetyTest extends TestCase
{
    private string $repoRoot;

    private string $forbiddenDb;

    private string $tempDir;

    /** @var list<string> */
    private array $tempFiles = [];

    private ?string $tempWorkdir = null;

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
        if ($this->tempWorkdir !== null && is_dir($this->tempWorkdir)) {
            @rmdir($this->tempWorkdir);
        }
        parent::tearDown();
    }

    public function test_missing_token_is_rejected(): void
    {
        [$db, $workdir] = $this->createDisposablePair(bin2hex(random_bytes(16)));
        $result = Phase3Isolation::validate([
            Phase3Isolation::ENV_TOKEN => null,
            Phase3Isolation::ENV_SQLITE_PATH => $db,
            Phase3Isolation::ENV_REPO_ROOT => $this->repoRoot,
            Phase3Isolation::ENV_FORBIDDEN_DB => $this->forbiddenDb,
            Phase3Isolation::ENV_WORKDIR => $workdir,
            'DB_DATABASE' => $db,
        ], $this->repoRoot);
        $this->assertFalse($result['ok']);
        $this->assertTrue($this->contains($result['errors'], 'token is missing'));
    }

    public function test_malformed_token_is_rejected(): void
    {
        $token = bin2hex(random_bytes(16));
        [$db, $workdir] = $this->createDisposablePair($token);
        $result = Phase3Isolation::validate([
            Phase3Isolation::ENV_TOKEN => 'bad',
            Phase3Isolation::ENV_SQLITE_PATH => $db,
            Phase3Isolation::ENV_REPO_ROOT => $this->repoRoot,
            Phase3Isolation::ENV_FORBIDDEN_DB => $this->forbiddenDb,
            Phase3Isolation::ENV_WORKDIR => $workdir,
            'DB_DATABASE' => $db,
        ], $this->repoRoot);
        $this->assertFalse($result['ok']);
        $this->assertTrue($this->contains($result['errors'], 'hexadecimal'));
    }

    public function test_mismatched_database_filename_is_rejected(): void
    {
        $token = bin2hex(random_bytes(16));
        $wrong = $this->tempDir.DIRECTORY_SEPARATOR.'equity-phase3-wrong-'.$token.'.sqlite';
        $h = fopen($wrong, 'x+b');
        $this->assertNotFalse($h);
        fclose($h);
        $this->tempFiles[] = $wrong;
        $workdir = $this->makeWorkdir($token);
        $result = Phase3Isolation::validate([
            Phase3Isolation::ENV_TOKEN => $token,
            Phase3Isolation::ENV_SQLITE_PATH => $wrong,
            Phase3Isolation::ENV_REPO_ROOT => $this->repoRoot,
            Phase3Isolation::ENV_FORBIDDEN_DB => $this->forbiddenDb,
            Phase3Isolation::ENV_WORKDIR => $workdir,
            'DB_DATABASE' => $wrong,
        ], $this->repoRoot);
        $this->assertFalse($result['ok']);
        $this->assertTrue($this->contains($result['errors'], 'basename must be exactly'));
    }

    public function test_wrong_repository_root_is_rejected(): void
    {
        $token = bin2hex(random_bytes(16));
        [$db, $workdir] = $this->createDisposablePair($token);
        $result = Phase3Isolation::validate([
            Phase3Isolation::ENV_TOKEN => $token,
            Phase3Isolation::ENV_SQLITE_PATH => $db,
            Phase3Isolation::ENV_REPO_ROOT => $this->tempDir,
            Phase3Isolation::ENV_FORBIDDEN_DB => $this->forbiddenDb,
            Phase3Isolation::ENV_WORKDIR => $workdir,
            'DB_DATABASE' => $db,
        ], $this->repoRoot);
        $this->assertFalse($result['ok']);
        $this->assertTrue($this->contains($result['errors'], 'bootstrap-derived project root'));
    }

    public function test_wrong_forbidden_path_is_rejected(): void
    {
        $token = bin2hex(random_bytes(16));
        [$db, $workdir] = $this->createDisposablePair($token);
        $result = Phase3Isolation::validate([
            Phase3Isolation::ENV_TOKEN => $token,
            Phase3Isolation::ENV_SQLITE_PATH => $db,
            Phase3Isolation::ENV_REPO_ROOT => $this->repoRoot,
            Phase3Isolation::ENV_FORBIDDEN_DB => $this->tempDir.DIRECTORY_SEPARATOR.'nope.sqlite',
            Phase3Isolation::ENV_WORKDIR => $workdir,
            'DB_DATABASE' => $db,
        ], $this->repoRoot);
        $this->assertFalse($result['ok']);
        $this->assertTrue($this->contains($result['errors'], 'database/database.sqlite'));
    }

    public function test_valid_snapshot_is_accepted(): void
    {
        $token = bin2hex(random_bytes(16));
        [$db, $workdir] = $this->createDisposablePair($token);
        $this->assertNotSame($db, $this->forbiddenDb);
        $result = Phase3Isolation::validate([
            Phase3Isolation::ENV_TOKEN => $token,
            Phase3Isolation::ENV_SQLITE_PATH => $db,
            Phase3Isolation::ENV_REPO_ROOT => $this->repoRoot,
            Phase3Isolation::ENV_FORBIDDEN_DB => $this->forbiddenDb,
            Phase3Isolation::ENV_WORKDIR => $workdir,
            'DB_DATABASE' => $db,
        ], $this->repoRoot);
        $this->assertTrue($result['ok'], implode("\n", $result['errors']));
    }

    public function test_unsafe_direct_phpunit_phase3_execution_fails_closed(): void
    {
        $php = PHP_BINARY;
        $phpunit = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'phpunit'.DIRECTORY_SEPARATOR.'phpunit'.DIRECTORY_SEPARATOR.'phpunit';
        $config = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'phpunit.phase3-sqlite-concurrency.xml';
        $this->assertFileExists($phpunit);
        $this->assertFileExists($config);

        $command = [$php, $phpunit, '--configuration', $config, '--filter', 'no_such_phase3_test', '--colors=never'];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = [];
        foreach (['PATH', 'PATHEXT', 'SystemRoot', 'SystemDrive', 'COMSPEC', 'TEMP', 'TMP', 'HOME', 'USERPROFILE'] as $key) {
            $value = getenv($key);
            if ($value !== false && $value !== '') {
                $env[$key] = $value;
            }
        }
        $env['APP_ENV'] = 'testing';
        $env['DB_CONNECTION'] = 'sqlite';
        $env['DB_DATABASE'] = $this->forbiddenDb;

        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes, $this->repoRoot, $env);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = (stream_get_contents($pipes[1]) ?: '').(stream_get_contents($pipes[2]) ?: '');
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('Phase 3', $output);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function createDisposablePair(string $token): array
    {
        $path = $this->tempDir.DIRECTORY_SEPARATOR.Phase3Isolation::databaseBasenameForToken($token);
        $h = fopen($path, 'x+b');
        $this->assertNotFalse($h);
        fclose($h);
        $this->tempFiles[] = $path;
        $real = realpath($path);
        $this->assertNotFalse($real);

        return [str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $real), $this->makeWorkdir($token)];
    }

    private function makeWorkdir(string $token): string
    {
        $dir = $this->tempDir.DIRECTORY_SEPARATOR.'equity-phase3-work-'.$token;
        $this->assertTrue(@mkdir($dir, 0700));
        $this->tempWorkdir = $dir;
        $real = realpath($dir);
        $this->assertNotFalse($real);

        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $real);
    }

    /**
     * @param  list<string>  $errors
     */
    private function contains(array $errors, string $needle): bool
    {
        foreach ($errors as $error) {
            if (str_contains($error, $needle)) {
                return true;
            }
        }

        return false;
    }
}
