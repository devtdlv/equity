<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tests\Integration\Concurrency\Phase3WorkdirCleanup;

/**
 * Negative safety tests for Phase 3 workdir cleanup (junctions / redirections).
 * Does not boot Laravel or open database/database.sqlite.
 */
final class Phase3WorkdirCleanupSafetyTest extends TestCase
{
    private string $repoRoot;

    private string $tempDir;

    /** @var list<string> */
    private array $ownedFiles = [];

    /** @var list<string> */
    private array $ownedDirs = [];

    /** @var list<string> */
    private array $junctionPaths = [];

    /** @var list<string> */
    private array $linkPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        $root = realpath(dirname(__DIR__, 3));
        $this->assertNotFalse($root);
        $this->repoRoot = Phase3WorkdirCleanup::normalize($root);

        $temp = realpath(sys_get_temp_dir());
        $this->assertNotFalse($temp);
        $this->tempDir = Phase3WorkdirCleanup::normalize($temp);
        $this->assertFalse(Phase3WorkdirCleanup::isWithinDirectory($this->tempDir, $this->repoRoot));
    }

    protected function tearDown(): void
    {
        foreach ($this->linkPaths as $link) {
            clearstatcache(true, $link);
            if (is_link($link) || file_exists($link)) {
                $this->silence(static function () use ($link): void {
                    if (is_link($link)) {
                        unlink($link);
                    } elseif (is_file($link)) {
                        unlink($link);
                    } elseif (is_dir($link)) {
                        rmdir($link);
                    }
                });
            }
        }

        foreach ($this->junctionPaths as $junction) {
            clearstatcache(true, $junction);
            $this->silence(static function () use ($junction): void {
                // rmdir removes a Windows junction without deleting its target.
                if (is_link($junction)) {
                    unlink($junction);
                } elseif (file_exists($junction) || is_dir($junction)) {
                    rmdir($junction);
                }
            });
        }

        foreach ($this->ownedFiles as $path) {
            clearstatcache(true, $path);
            if (is_file($path) && ! is_link($path)) {
                $this->silence(static fn () => unlink($path));
            }
        }

        foreach (array_reverse($this->ownedDirs) as $dir) {
            clearstatcache(true, $dir);
            if (! is_dir($dir) || is_link($dir)) {
                continue;
            }
            if (Phase3WorkdirCleanup::isUnsafeRedirection($dir)) {
                continue;
            }
            $entries = @scandir($dir);
            if (! is_array($entries)) {
                continue;
            }
            $remaining = array_values(array_filter(
                $entries,
                static fn (string $name): bool => $name !== '.' && $name !== '..',
            ));
            if ($remaining === []) {
                $this->silence(static fn () => rmdir($dir));
            }
        }

        parent::tearDown();
    }

    public function test_clean_workdir_with_regular_files_succeeds(): void
    {
        $token = bin2hex(random_bytes(16));
        $workdir = $this->makeOwnedWorkdir($token);
        $nested = $workdir.DIRECTORY_SEPARATOR.'nested';
        $this->assertTrue(@mkdir($nested, 0700));
        $this->ownedDirs[] = $nested;

        $file = $nested.DIRECTORY_SEPARATOR.'payload.json';
        $this->assertNotFalse(file_put_contents($file, '{"ok":true}'));
        $this->ownedFiles[] = $file;

        $result = Phase3WorkdirCleanup::cleanup($workdir, $token, $this->tempDir, $this->repoRoot);

        $this->assertSame([], $result['errors'], implode(' | ', $result['errors']));
        $this->assertSame([], $result['left']);
        $this->assertFalse(is_dir($workdir));
        $this->assertContains($file, $result['deleted']);
        $this->assertContains($workdir, $result['deleted']);
    }

    public function test_refuses_unverified_token_workdir(): void
    {
        $token = bin2hex(random_bytes(16));
        $other = bin2hex(random_bytes(16));
        $workdir = $this->makeOwnedWorkdir($token);
        $marker = $workdir.DIRECTORY_SEPARATOR.'keep.txt';
        $this->assertNotFalse(file_put_contents($marker, 'keep'));
        $this->ownedFiles[] = $marker;

        $result = Phase3WorkdirCleanup::cleanup($workdir, $other, $this->tempDir, $this->repoRoot);

        $this->assertNotSame([], $result['errors']);
        $this->assertTrue($this->contains($result['errors'], 'unverified workdir'));
        $this->assertFileExists($marker);
        $this->assertDirectoryExists($workdir);
    }

    public function test_refuses_workdir_inside_repository(): void
    {
        $token = bin2hex(random_bytes(16));
        $fakeTemp = $this->repoRoot;
        $workdir = $this->tempDir.DIRECTORY_SEPARATOR.'equity-phase3-work-'.$token;
        $this->assertTrue(@mkdir($workdir, 0700));
        $this->ownedDirs[] = $workdir;

        $result = Phase3WorkdirCleanup::cleanup($workdir, $token, $fakeTemp, $this->repoRoot);

        $this->assertNotSame([], $result['errors']);
        $this->assertTrue(
            $this->contains($result['errors'], 'unverified workdir')
            || $this->contains($result['errors'], 'incomplete')
            || $this->contains($result['errors'], 'dirname'),
        );
        $this->assertDirectoryExists($workdir);
    }

    public function test_symlink_file_is_left_in_place_and_siblings_still_cleanable(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('symlink() is unavailable.');
        }

        $token = bin2hex(random_bytes(16));
        $workdir = $this->makeOwnedWorkdir($token);

        $target = $this->tempDir.DIRECTORY_SEPARATOR.'equity-phase3-symlink-target-'.$token.'.txt';
        $this->assertNotFalse(file_put_contents($target, 'canary-outside'));
        $this->ownedFiles[] = $target;

        $link = $workdir.DIRECTORY_SEPARATOR.'evil-link.txt';
        $created = $this->silence(static fn () => symlink($target, $link));
        if ($created !== true) {
            $this->markTestSkipped('Could not create file symlink in this environment.');
        }
        $this->linkPaths[] = $link;
        $this->assertTrue(is_link($link));

        $sibling = $workdir.DIRECTORY_SEPARATOR.'safe.txt';
        $this->assertNotFalse(file_put_contents($sibling, 'safe'));
        $this->ownedFiles[] = $sibling;

        $result = Phase3WorkdirCleanup::cleanup($workdir, $token, $this->tempDir, $this->repoRoot);

        $this->assertTrue($this->contains($result['errors'], 'symlink, junction, or redirected path'));
        $this->assertContains($link, $result['left']);
        $this->assertFileExists($target);
        $this->assertSame('canary-outside', file_get_contents($target));
        $this->assertFileDoesNotExist($sibling);
        // Workdir root remains because the unsafe link was left behind.
        $this->assertDirectoryExists($workdir);
        $this->assertTrue(is_link($link) || file_exists($link));

        $this->silence(static function () use ($link): void {
            if (is_link($link)) {
                unlink($link);
            }
        });
        $this->linkPaths = array_values(array_filter(
            $this->linkPaths,
            static fn (string $path): bool => $path !== $link,
        ));
    }

    public function test_windows_directory_junction_is_not_followed_and_target_survives(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Directory junction coverage is Windows-specific.');
        }

        $token = bin2hex(random_bytes(16));
        $workdir = $this->makeOwnedWorkdir($token);

        $outside = $this->tempDir.DIRECTORY_SEPARATOR.'equity-phase3-junction-target-'.$token;
        $this->assertTrue(@mkdir($outside, 0700));
        $this->ownedDirs[] = $outside;

        $canary = $outside.DIRECTORY_SEPARATOR.'DO_NOT_DELETE.txt';
        $this->assertNotFalse(file_put_contents($canary, 'outside-canary'));
        $this->ownedFiles[] = $canary;

        $junction = $workdir.DIRECTORY_SEPARATOR.'junction-out';
        $created = $this->createDirectoryJunction($junction, $outside);
        if (! $created) {
            $this->markTestSkipped('Could not create directory junction (mklink /J).');
        }
        $this->junctionPaths[] = $junction;
        $this->assertTrue(Phase3WorkdirCleanup::isUnsafeRedirection($junction));

        $safe = $workdir.DIRECTORY_SEPARATOR.'safe-payload.json';
        $this->assertNotFalse(file_put_contents($safe, '{}'));
        $this->ownedFiles[] = $safe;

        $result = Phase3WorkdirCleanup::cleanup($workdir, $token, $this->tempDir, $this->repoRoot);

        $this->assertTrue($this->contains($result['errors'], 'symlink, junction, or redirected path'));
        $this->assertContains($junction, $result['left']);
        $this->assertFileExists($canary);
        $this->assertSame('outside-canary', file_get_contents($canary));
        $this->assertDirectoryExists($outside);
        $this->assertFileDoesNotExist($safe);
        $this->assertDirectoryExists($workdir);

        // Detach the junction so tearDown can remove the owned workdir without following it.
        $this->silence(static fn () => rmdir($junction));
        $this->junctionPaths = array_values(array_filter(
            $this->junctionPaths,
            static fn (string $path): bool => $path !== $junction,
        ));
    }

    public function test_is_unsafe_redirection_detects_windows_junction(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Directory junction coverage is Windows-specific.');
        }

        $token = bin2hex(random_bytes(16));
        $workdir = $this->makeOwnedWorkdir($token);
        $outside = $this->tempDir.DIRECTORY_SEPARATOR.'equity-phase3-junction-detect-'.$token;
        $this->assertTrue(@mkdir($outside, 0700));
        $this->ownedDirs[] = $outside;

        $junction = $workdir.DIRECTORY_SEPARATOR.'junc';
        if (! $this->createDirectoryJunction($junction, $outside)) {
            $this->markTestSkipped('Could not create directory junction (mklink /J).');
        }
        $this->junctionPaths[] = $junction;

        $this->assertTrue(Phase3WorkdirCleanup::isUnsafeRedirection($junction));
        $this->assertFalse(Phase3WorkdirCleanup::isUnsafeRedirection($workdir));

        $this->silence(static fn () => rmdir($junction));
        $this->junctionPaths = array_values(array_filter(
            $this->junctionPaths,
            static fn (string $path): bool => $path !== $junction,
        ));
    }

    public function test_path_outside_workdir_prefix_is_rejected_by_scope_helper(): void
    {
        $token = bin2hex(random_bytes(16));
        $workdir = $this->makeOwnedWorkdir($token);
        $outside = $this->tempDir.DIRECTORY_SEPARATOR.'equity-phase3-outside-'.$token.'.txt';
        $this->assertNotFalse(file_put_contents($outside, 'x'));
        $this->ownedFiles[] = $outside;

        $this->assertTrue(Phase3WorkdirCleanup::isWithinDirectory($workdir, $this->tempDir));
        $this->assertFalse(Phase3WorkdirCleanup::isWithinDirectory($outside, $workdir));
        $this->assertFalse(Phase3WorkdirCleanup::isWithinDirectory($this->repoRoot.DIRECTORY_SEPARATOR.'database', $workdir));
    }

    private function makeOwnedWorkdir(string $token): string
    {
        $dir = $this->tempDir.DIRECTORY_SEPARATOR.'equity-phase3-work-'.$token;
        $this->assertTrue(@mkdir($dir, 0700));
        $this->ownedDirs[] = $dir;
        $real = realpath($dir);
        $this->assertNotFalse($real);

        return Phase3WorkdirCleanup::normalize($real);
    }

    private function createDirectoryJunction(string $junctionPath, string $targetPath): bool
    {
        if (file_exists($junctionPath) || is_link($junctionPath)) {
            return false;
        }

        $command = sprintf(
            'cmd /c mklink /J %s %s',
            escapeshellarg($junctionPath),
            escapeshellarg($targetPath),
        );
        exec($command, $output, $code);

        return $code === 0 && (is_dir($junctionPath) || is_link($junctionPath));
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

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    private function silence(callable $callback): mixed
    {
        set_error_handler(static fn (): bool => true);
        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }
}
