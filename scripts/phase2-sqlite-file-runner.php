<?php

/**
 * Phase 2 file-based SQLite financial integration runner.
 *
 * Safety gate → exclusive create disposable DB outside the repo → migrate/seed → PHPUnit → cleanup.
 * Never targets database/database.sqlite. Never reads or writes the real .env file.
 */

declare(strict_types=1);

$repoRoot = dirname(__DIR__);
$repoReal = realpath($repoRoot);
if ($repoReal === false) {
    fwrite(STDERR, "FAIL safety gate: repository root could not be resolved.\n");
    exit(2);
}
$repoReal = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $repoReal);

$forbiddenLogical = $repoReal.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite';
$forbiddenReal = $forbiddenLogical;
$forbiddenResolved = realpath($forbiddenLogical);
if ($forbiddenResolved !== false) {
    $forbiddenReal = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $forbiddenResolved);
}

$tempRoot = sys_get_temp_dir();
$tempReal = realpath($tempRoot);
if ($tempReal === false) {
    fwrite(STDERR, "FAIL safety gate: system temp directory could not be resolved.\n");
    exit(2);
}
$tempReal = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $tempReal);

if ($tempReal === $repoReal || str_starts_with($tempReal, $repoReal.DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "FAIL safety gate: system temp directory is inside the repository.\n");
    exit(2);
}

$configCache = $repoReal.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'config.php';
if (is_file($configCache)) {
    fwrite(STDERR, "FAIL safety gate: Laravel config cache exists at bootstrap/cache/config.php.\n");
    exit(2);
}

$token = bin2hex(random_bytes(16));
$dbBasename = 'equity-phase2-'.$token.'.sqlite';
$dbPath = $tempReal.DIRECTORY_SEPARATOR.$dbBasename;
$metaPath = $dbPath.'.meta.json';
$sidecarPaths = [
    $dbPath.'-wal',
    $dbPath.'-shm',
    $dbPath.'-journal',
];

foreach ([$dbPath, $metaPath, ...$sidecarPaths] as $candidate) {
    if (file_exists($candidate)) {
        fwrite(STDERR, "FAIL safety gate: disposable path already exists: {$candidate}\n");
        exit(2);
    }
}

if ($dbPath === $forbiddenReal || str_starts_with($dbPath, $repoReal.DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "FAIL safety gate: disposable path is forbidden or inside the repository.\n");
    exit(2);
}

/**
 * Create a new empty file exclusively (fail if it already exists). Never truncate.
 */
function equity_phase2_exclusive_create(string $path): bool
{
    $handle = @fopen($path, 'x+b');
    if ($handle === false) {
        return false;
    }
    fclose($handle);

    return is_file($path) && ! is_link($path);
}

if (! equity_phase2_exclusive_create($dbPath)) {
    fwrite(STDERR, "FAIL safety gate: exclusive create of disposable SQLite file failed.\n");
    exit(2);
}

$dbReal = realpath($dbPath);
if ($dbReal === false) {
    @unlink($dbPath);
    fwrite(STDERR, "FAIL safety gate: disposable SQLite file could not be resolved after create.\n");
    exit(2);
}
$dbReal = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dbReal);

if ($dbReal === $forbiddenReal
    || str_starts_with($dbReal, $repoReal.DIRECTORY_SEPARATOR)
    || basename($dbReal) !== $dbBasename
    || is_link($dbReal)) {
    @unlink($dbPath);
    fwrite(STDERR, "FAIL safety gate: resolved disposable path is unsafe.\n");
    exit(2);
}

if (! equity_phase2_exclusive_create($metaPath)) {
    @unlink($dbReal);
    fwrite(STDERR, "FAIL safety gate: exclusive create of meta file failed.\n");
    exit(2);
}

$appKey = 'base64:'.base64_encode(random_bytes(32));
$sentinelCache = $repoReal.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'phpunit-config-must-not-exist.php';

$childEnv = [
    'APP_ENV' => 'testing',
    'APP_KEY' => $appKey,
    'APP_DEBUG' => 'true',
    'APP_URL' => 'http://localhost',
    'APP_MAINTENANCE_DRIVER' => 'file',
    'BCRYPT_ROUNDS' => '4',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array',
    'PULSE_ENABLED' => 'false',
    'TELESCOPE_ENABLED' => 'false',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $dbReal,
    'DB_URL' => '',
    'DATABASE_URL' => '',
    'DB_SOCKET' => '',
    'DB_UNIX_SOCKET' => '',
    'DB_HOST' => '',
    'DB_PORT' => '',
    'DB_USERNAME' => '',
    'DB_PASSWORD' => '',
    'DB_DSN' => '',
    'MYSQL_PWD' => '',
    'DB_FOREIGN_KEYS' => 'true',
    'DB_BUSY_TIMEOUT' => '5000',
    'DB_JOURNAL_MODE' => 'wal',
    'APP_CONFIG_CACHE' => $sentinelCache,
    'EQUITY_PHASE2_SQLITE_PATH' => $dbReal,
    'EQUITY_PHASE2_TOKEN' => $token,
    'EQUITY_PHASE2_REPO_ROOT' => $repoReal,
    'EQUITY_PHASE2_FORBIDDEN_DB' => $forbiddenReal,
];

$meta = [
    'token' => $token,
    'database' => $dbReal,
    'forbidden' => $forbiddenReal,
    'repo' => $repoReal,
    'created_at' => gmdate('c'),
];
file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

fwrite(STDOUT, "PASS safety gate\n");
fwrite(STDOUT, "Disposable database: {$dbReal}\n");
fwrite(STDOUT, "Token: {$token}\n");

/**
 * @param  list<string>  $command
 * @param  array<string, string>  $extraEnv
 * @return array{code: int, output: string}
 */
function equity_phase2_run(array $command, array $extraEnv, string $cwd): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $env = [];
    foreach (['PATH', 'PATHEXT', 'SystemRoot', 'SystemDrive', 'COMSPEC', 'TEMP', 'TMP', 'HOME', 'USERPROFILE', 'HOMEDRIVE', 'HOMEPATH', 'PHP_INI_SCAN_DIR'] as $key) {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            $env[$key] = $value;
        }
    }
    $env = array_merge($env, $extraEnv);

    $pipes = [];
    $process = proc_open($command, $descriptors, $pipes, $cwd, $env);
    if (! is_resource($process)) {
        return ['code' => 99, 'output' => 'proc_open failed'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    return ['code' => $code, 'output' => $stdout.$stderr];
}

/**
 * Delete only artifacts proven to belong to this runner invocation.
 *
 * @param  list<string>  $candidates
 * @return array{deleted: list<string>, left: list<string>, errors: list<string>}
 */
function equity_phase2_cleanup(
    array $candidates,
    string $token,
    string $expectedDbBasename,
    string $tempReal,
    string $repoReal,
    string $forbiddenReal,
): array {
    $result = ['deleted' => [], 'left' => [], 'errors' => []];
    $allowedExactBasenames = [
        $expectedDbBasename => true,
        $expectedDbBasename.'.meta.json' => true,
        $expectedDbBasename.'-wal' => true,
        $expectedDbBasename.'-shm' => true,
        $expectedDbBasename.'-journal' => true,
    ];

    foreach ($candidates as $path) {
        if (! file_exists($path)) {
            continue;
        }

        clearstatcache(true, $path);

        if (is_link($path)) {
            $result['left'][] = $path;
            $result['errors'][] = "Refused to delete symlink: {$path}";
            continue;
        }

        if (! is_file($path)) {
            $result['left'][] = $path;
            $result['errors'][] = "Refused to delete non-file: {$path}";
            continue;
        }

        $basename = basename($path);
        if (! isset($allowedExactBasenames[$basename])) {
            $result['left'][] = $path;
            $result['errors'][] = "Refused to delete unexpected basename: {$path}";
            continue;
        }

        if (! str_contains($basename, $token)) {
            $result['left'][] = $path;
            $result['errors'][] = "Refused to delete file without run token: {$path}";
            continue;
        }

        $resolved = realpath($path);
        if ($resolved === false) {
            $result['left'][] = $path;
            $result['errors'][] = "Refused to delete unresolved path: {$path}";
            continue;
        }
        $resolved = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $resolved);

        if ($resolved === $forbiddenReal || str_starts_with($resolved, $repoReal.DIRECTORY_SEPARATOR)) {
            $result['left'][] = $path;
            $result['errors'][] = "Refused to delete protected or in-repo path: {$path}";
            continue;
        }

        $parent = dirname($resolved);
        if ($parent !== $tempReal) {
            $result['left'][] = $path;
            $result['errors'][] = "Refused to delete file outside owned temp directory: {$path}";
            continue;
        }

        if (basename($resolved) !== $basename) {
            $result['left'][] = $path;
            $result['errors'][] = "Refused to delete path whose resolved basename drifted: {$path}";
            continue;
        }

        if (@unlink($resolved)) {
            $result['deleted'][] = $resolved;
        } else {
            $result['left'][] = $resolved;
            $result['errors'][] = "Could not delete owned artifact: {$resolved}";
        }
    }

    return $result;
}

$php = PHP_BINARY;
$artisan = $repoReal.DIRECTORY_SEPARATOR.'artisan';
$phpunit = $repoReal.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'phpunit';
if (PHP_OS_FAMILY === 'Windows') {
    $phpunitCmd = [$php, $repoReal.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'phpunit'.DIRECTORY_SEPARATOR.'phpunit'.DIRECTORY_SEPARATOR.'phpunit'];
} else {
    $phpunitCmd = [$php, $phpunit];
}

$exitCode = 0;
$ownedCandidates = [$dbReal, $metaPath, $dbReal.'-wal', $dbReal.'-shm', $dbReal.'-journal'];

try {
    fwrite(STDOUT, "Running migrations against disposable database only...\n");
    $migrate = equity_phase2_run(
        [$php, $artisan, 'migrate', '--force', '--no-interaction'],
        $childEnv,
        $repoReal,
    );
    fwrite(STDOUT, $migrate['output']);
    if ($migrate['code'] !== 0) {
        fwrite(STDERR, "FAIL migrate (exit {$migrate['code']})\n");
        $exitCode = $migrate['code'] ?: 1;
    } else {
        fwrite(STDOUT, "Seeding CurrencySeeder against disposable database only...\n");
        $seed = equity_phase2_run(
            [$php, $artisan, 'db:seed', '--class=CurrencySeeder', '--force', '--no-interaction'],
            $childEnv,
            $repoReal,
        );
        fwrite(STDOUT, $seed['output']);
        if ($seed['code'] !== 0) {
            fwrite(STDERR, "FAIL seed (exit {$seed['code']})\n");
            $exitCode = $seed['code'] ?: 1;
        } else {
            fwrite(STDOUT, "Running Phase 2 PHPUnit suite...\n");
            $test = equity_phase2_run(
                array_merge($phpunitCmd, [
                    '--configuration',
                    $repoReal.DIRECTORY_SEPARATOR.'phpunit.phase2-sqlite-file.xml',
                    '--colors=never',
                ]),
                $childEnv,
                $repoReal,
            );
            fwrite(STDOUT, $test['output']);
            if ($test['code'] !== 0) {
                fwrite(STDERR, "FAIL Phase 2 tests (exit {$test['code']})\n");
                $exitCode = $test['code'];
            } else {
                fwrite(STDOUT, "PASS Phase 2 runner\n");
            }
        }
    }
} finally {
    $cleanup = equity_phase2_cleanup(
        $ownedCandidates,
        $token,
        $dbBasename,
        $tempReal,
        $repoReal,
        $forbiddenReal,
    );

    fwrite(STDOUT, 'Cleanup deleted: '.implode(', ', $cleanup['deleted'] !== [] ? $cleanup['deleted'] : ['(none)'])."\n");
    if ($cleanup['left'] !== []) {
        fwrite(STDOUT, 'Cleanup left in place: '.implode(', ', $cleanup['left'])."\n");
    }
    if ($cleanup['errors'] !== []) {
        fwrite(STDERR, 'Cleanup failures: '.implode(' | ', $cleanup['errors'])."\n");
        if ($exitCode === 0) {
            $exitCode = 3;
        }
    }
}

exit($exitCode);
