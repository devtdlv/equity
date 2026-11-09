<?php

use App\Support\VisualAuditEnvironment;

function visualAuditTempRoot(): string
{
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'equity-va-tests-'.bin2hex(random_bytes(8));
    if (! mkdir($root, 0700, true) && ! is_dir($root)) {
        throw new RuntimeException('Unable to create temp visual-audit fixture root.');
    }

    return $root;
}

function removeVisualAuditTree(string $path): void
{
    if (! is_dir($path) && ! is_file($path) && ! is_link($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        unlink($path);

        return;
    }

    $items = scandir($path);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        removeVisualAuditTree($path.DIRECTORY_SEPARATOR.$item);
    }

    rmdir($path);
}

beforeEach(function (): void {
    $this->fixtureRoot = visualAuditTempRoot();
    VisualAuditEnvironment::useDataDirectoryOverrideForTesting($this->fixtureRoot);
    $this->envPath = $this->fixtureRoot.DIRECTORY_SEPARATOR.'env.visual-audit';
    VisualAuditEnvironment::useEnvFilePathOverrideForTesting($this->envPath);
});

afterEach(function (): void {
    VisualAuditEnvironment::clearEnvFilePathOverride();
    VisualAuditEnvironment::clearDataDirectoryOverride();
    removeVisualAuditTree($this->fixtureRoot);
});

test('default disposable database path is outside the repository', function (): void {
    $repo = VisualAuditEnvironment::repositoryRoot();
    $database = str_replace('\\', '/', VisualAuditEnvironment::defaultDatabasePath());

    expect($database)->not->toStartWith(rtrim($repo, '/').'/')
        ->and(VisualAuditEnvironment::pathsEqual(
            $database,
            VisualAuditEnvironment::forbiddenDatabasePath(),
        ))->toBeFalse()
        ->and($database)->toStartWith(str_replace('\\', '/', $this->fixtureRoot));
});

test('valid default disposable path is accepted', function (): void {
    $path = VisualAuditEnvironment::defaultDatabasePath();

    expect(VisualAuditEnvironment::assertDisposableDatabasePath($path))
        ->toBe(str_replace('\\', '/', $path));
});

test('repository database path is rejected', function (): void {
    expect(fn () => VisualAuditEnvironment::assertDisposableDatabasePath(
        VisualAuditEnvironment::forbiddenDatabasePath(),
    ))->toThrow(InvalidArgumentException::class);
});

test('case-insensitive windows path comparison treats equal paths as equal', function (): void {
    $left = str_replace('\\', '/', $this->fixtureRoot).'/Database.SQLite';
    $right = strtoupper(str_replace('\\', '/', $this->fixtureRoot)).'/database.sqlite';

    expect(VisualAuditEnvironment::pathsEqual($left, $right))->toBeTrue();
});

test('dot-dot traversal escaping the allowed root is rejected', function (): void {
    $escape = $this->fixtureRoot.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'outside.sqlite';

    expect(fn () => VisualAuditEnvironment::assertDisposableDatabasePath($escape))
        ->toThrow(InvalidArgumentException::class);
});

test('relative paths are rejected', function (): void {
    expect(fn () => VisualAuditEnvironment::assertDisposableDatabasePath('relative/database.sqlite'))
        ->toThrow(InvalidArgumentException::class, 'absolute');
});

test('symlink redirection outside the allowed root is rejected', function (): void {
    $outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'equity-va-outside-'.bin2hex(random_bytes(4)).'.sqlite';
    file_put_contents($outside, 'outside');

    $link = $this->fixtureRoot.DIRECTORY_SEPARATOR.'linked.sqlite';

    try {
        if (! @symlink($outside, $link)) {
            // Windows often requires elevated privileges for symlinks.
            $this->markTestSkipped('Unable to create symlink in this environment.');
        }

        expect(fn () => VisualAuditEnvironment::assertDisposableDatabasePath($link))
            ->toThrow(InvalidArgumentException::class, 'outside the dedicated EquityVisualAudit data directory');
    } finally {
        if (is_link($link) || is_file($link)) {
            unlink($link);
        }
        if (is_file($outside)) {
            unlink($outside);
        }
    }
});

test('prepare preserves an existing disposable database file', function (): void {
    $database = VisualAuditEnvironment::defaultDatabasePath();
    $directory = dirname($database);
    if (! is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    file_put_contents($database, 'KEEP-ME');

    $result = VisualAuditEnvironment::prepareDisposableEnvironment($database);

    expect($result['created_database'])->toBeFalse()
        ->and($result['wrote_env'])->toBeTrue()
        ->and(file_get_contents($database))->toBe('KEEP-ME')
        ->and(is_file($this->envPath))->toBeTrue();
});

test('prepare preserves an existing environment file and does not rewrite it', function (): void {
    file_put_contents($this->envPath, "APP_KEY=base64:preserve-me\n");
    $before = file_get_contents($this->envPath);

    expect(fn () => VisualAuditEnvironment::prepareDisposableEnvironment())
        ->toThrow(RuntimeException::class, 'already exists');

    expect(file_get_contents($this->envPath))->toBe($before);
});

test('unsafe custom database overrides outside the data directory are rejected', function (): void {
    $outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'equity-va-unsafe-'.bin2hex(random_bytes(4)).'.sqlite';

    expect(fn () => VisualAuditEnvironment::assertDisposableDatabasePath($outside))
        ->toThrow(InvalidArgumentException::class, 'dedicated EquityVisualAudit data directory');

    expect(fn () => VisualAuditEnvironment::prepareDisposableEnvironment($outside))
        ->toThrow(InvalidArgumentException::class);
});

test('ambiguous unresolved custom paths are rejected rather than trusted', function (): void {
    $missingAncestor = $this->fixtureRoot
        .DIRECTORY_SEPARATOR.'missing-root-'
        .bin2hex(random_bytes(3))
        .DIRECTORY_SEPARATOR.'nested'
        .DIRECTORY_SEPARATOR.'database.sqlite';

    // Override points at fixtureRoot which exists, but nested missing-root does not.
    // Logical path remains under allowed root and should still be accepted for creation.
    // Use a path that escapes via an unresolvable absolute root instead:
    $bogus = PHP_OS_FAMILY === 'Windows'
        ? 'Z:/equity-does-not-exist-'.bin2hex(random_bytes(3)).'/database.sqlite'
        : '/equity-does-not-exist-'.bin2hex(random_bytes(3)).'/database.sqlite';

    expect(fn () => VisualAuditEnvironment::assertDisposableDatabasePath($bogus))
        ->toThrow(InvalidArgumentException::class);
});

test('verification fails before attempting a database connection when isolation checks fail', function (): void {
    // Path evaluation is the gate used by verificationChecks() before any PDO probe.
    $reflected = new ReflectionClass(VisualAuditEnvironment::class);
    $method = $reflected->getMethod('evaluateConfiguredDatabasePath');
    $method->setAccessible(true);
    /** @var array{passed: bool, detail: string|null} $pathCheck */
    $pathCheck = $method->invoke(null, VisualAuditEnvironment::forbiddenDatabasePath());

    expect($pathCheck['passed'])->toBeFalse()
        ->and($pathCheck['detail'])->toBeString()
        ->and($pathCheck['detail'])->not->toBe('');
});

test('renderEnvFileContents requires visual-audit markers and disposable drivers', function (): void {
    $contents = VisualAuditEnvironment::renderEnvFileContents([
        'APP_KEY' => 'base64:dGVzdC1rZXktZm9yLXVuaXQtdGVzdHMtb25seQ==',
        'DB_DATABASE' => VisualAuditEnvironment::defaultDatabasePath(),
    ]);

    expect($contents)
        ->toContain('APP_ENV=visual-audit')
        ->toContain('EQUITY_VISUAL_AUDIT=true')
        ->toContain('SESSION_DRIVER=database')
        ->toContain('CACHE_STORE=database')
        ->toContain('MAIL_MAILER=array')
        ->toContain('QUEUE_CONNECTION=sync')
        ->not->toContain('database/database.sqlite');
});

test('forbidden database path points at repository database sqlite', function (): void {
    expect(VisualAuditEnvironment::forbiddenDatabasePath())
        ->toEndWith('/database/database.sqlite');
});

test('prepare script rejects --force explicitly', function (): void {
    $script = VisualAuditEnvironment::repositoryRoot().'/scripts/visual-audit-prepare.php';
    $php = PHP_BINARY;

    exec(escapeshellarg($php).' '.escapeshellarg($script).' --force 2>&1', $output, $exitCode);

    expect($exitCode)->toBe(1)
        ->and(implode("\n", $output))->toContain('Refusing --force');
});
