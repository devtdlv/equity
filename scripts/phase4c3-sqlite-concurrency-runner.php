<?php

/**
 * Phase 4C.3 SQLite concurrency/reliability runner.
 *
 * Safety gate → exclusive disposable DB + workdir + env file → isolated artisan migrate/seed → PHPUnit.
 * Never targets database/database.sqlite. Never reads or writes the real .env file.
 * Laravel boots via scripts/phase4c3-artisan.php with Dotenv redirected to the disposable env file.
 */

declare(strict_types=1);

$repoRoot = dirname(__DIR__);
require $repoRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';

use Tests\Integration\Concurrency\Phase3WorkdirCleanup;

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
$dbBasename = 'equity-phase4c3-'.$token.'.sqlite';
$dbPath = $tempReal.DIRECTORY_SEPARATOR.$dbBasename;
$metaPath = $dbPath.'.meta.json';
$workDir = $tempReal.DIRECTORY_SEPARATOR.'equity-phase4c3-work-'.$token;
$sidecarPaths = [$dbPath.'-wal', $dbPath.'-shm', $dbPath.'-journal'];

foreach ([$dbPath, $metaPath, ...$sidecarPaths] as $candidate) {
    if (file_exists($candidate)) {
        fwrite(STDERR, "FAIL safety gate: disposable path already exists: {$candidate}\n");
        exit(2);
    }
}
if (file_exists($workDir)) {
    fwrite(STDERR, "FAIL safety gate: workdir already exists: {$workDir}\n");
    exit(2);
}

if ($dbPath === $forbiddenReal || str_starts_with($dbPath, $repoReal.DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "FAIL safety gate: disposable path is forbidden or inside the repository.\n");
    exit(2);
}

function EQUITY_PHASE4C3_exclusive_create(string $path): bool
{
    $handle = @fopen($path, 'x+b');
    if ($handle === false) {
        return false;
    }
    fclose($handle);

    return is_file($path) && ! is_link($path);
}

if (! EQUITY_PHASE4C3_exclusive_create($dbPath)) {
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

if (! mkdir($workDir, 0700) || ! is_dir($workDir)) {
    @unlink($dbReal);
    fwrite(STDERR, "FAIL safety gate: could not create Phase 4C.3 workdir.\n");
    exit(2);
}
$workReal = realpath($workDir);
if ($workReal === false || str_starts_with(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $workReal), $repoReal.DIRECTORY_SEPARATOR)) {
    @unlink($dbReal);
    @rmdir($workDir);
    fwrite(STDERR, "FAIL safety gate: resolved workdir is unsafe.\n");
    exit(2);
}
$workReal = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $workReal);

if (! EQUITY_PHASE4C3_exclusive_create($metaPath)) {
    @unlink($dbReal);
    @rmdir($workReal);
    fwrite(STDERR, "FAIL safety gate: exclusive create of meta file failed.\n");
    exit(2);
}

$envBasename = 'equity-phase4c3-'.$token.'.env';
$envPath = $workReal.DIRECTORY_SEPARATOR.$envBasename;
if (strcasecmp($envBasename, '.env') === 0) {
    @unlink($dbReal);
    @unlink($metaPath);
    @rmdir($workReal);
    fwrite(STDERR, "FAIL safety gate: refused to create a disposable file named .env.\n");
    exit(2);
}
if (! EQUITY_PHASE4C3_exclusive_create($envPath)) {
    @unlink($dbReal);
    @unlink($metaPath);
    @rmdir($workReal);
    fwrite(STDERR, "FAIL safety gate: exclusive create of disposable environment file failed.\n");
    exit(2);
}
// Empty disposable env file: process env supplies all keys; Dotenv must not open repo .env.
$envReal = realpath($envPath);
if ($envReal === false
    || str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $envReal) !== $envPath
    || basename($envReal) !== $envBasename
    || is_link($envPath)
    || dirname(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $envReal)) !== $workReal) {
    @unlink($dbReal);
    @unlink($metaPath);
    @unlink($envPath);
    @rmdir($workReal);
    fwrite(STDERR, "FAIL safety gate: resolved disposable environment file is unsafe.\n");
    exit(2);
}
$envReal = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $envReal);

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
    'EQUITY_PHASE4C3_SQLITE_PATH' => $dbReal,
    'EQUITY_PHASE4C3_TOKEN' => $token,
    'EQUITY_PHASE4C3_REPO_ROOT' => $repoReal,
    'EQUITY_PHASE4C3_FORBIDDEN_DB' => $forbiddenReal,
    'EQUITY_PHASE4C3_WORKDIR' => $workReal,
    'EQUITY_PHASE4C3_ENV_FILE' => $envReal,
];

file_put_contents($metaPath, json_encode([
    'token' => $token,
    'database' => $dbReal,
    'workdir' => $workReal,
    'environment_file' => $envReal,
    'forbidden' => $forbiddenReal,
    'repo' => $repoReal,
    'created_at' => gmdate('c'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

fwrite(STDOUT, "PASS safety gate\n");
fwrite(STDOUT, "Disposable database: {$dbReal}\n");
fwrite(STDOUT, "Workdir: {$workReal}\n");
fwrite(STDOUT, "Disposable env file: {$envReal}\n");
fwrite(STDOUT, "Token: {$token}\n");

/**
 * @param  list<string>  $command
 * @param  array<string, string>  $extraEnv
 * @return array{code: int, output: string}
 */
function EQUITY_PHASE4C3_run(array $command, array $extraEnv, string $cwd): array
{
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
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

    return ['code' => proc_close($process), 'output' => $stdout.$stderr];
}

/**
 * @param  list<string>  $candidates
 * @return array{deleted: list<string>, left: list<string>, errors: list<string>}
 */
function EQUITY_PHASE4C3_cleanup_files(
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
        if (is_link($path) || ! is_file($path)) {
            $result['left'][] = $path;
            $result['errors'][] = "Refused to delete non-regular file: {$path}";
            continue;
        }
        $basename = basename($path);
        if (! isset($allowedExactBasenames[$basename]) || ! str_contains($basename, $token)) {
            $result['left'][] = $path;
            $result['errors'][] = "Refused to delete unverified basename: {$path}";
            continue;
        }
        $resolved = realpath($path);
        if ($resolved === false) {
            $result['left'][] = $path;
            $result['errors'][] = "Refused to delete unresolved path: {$path}";
            continue;
        }
        $resolved = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $resolved);
        if ($resolved === $forbiddenReal || str_starts_with($resolved, $repoReal.DIRECTORY_SEPARATOR) || dirname($resolved) !== $tempReal) {
            $result['left'][] = $path;
            $result['errors'][] = "Refused to delete path outside owned temp scope: {$path}";
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

/**
 * @return array{deleted: list<string>, left: list<string>, errors: list<string>}
 */
function EQUITY_PHASE4C3_cleanup_workdir(string $workReal, string $token, string $tempReal, string $repoReal): array
{
    return Phase3WorkdirCleanup::cleanup($workReal, $token, $tempReal, $repoReal);
}

$php = PHP_BINARY;
$isolatedArtisan = $repoReal.DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'phase4c3-artisan.php';
$phpunitCmd = [$php, $repoReal.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'phpunit'.DIRECTORY_SEPARATOR.'phpunit'.DIRECTORY_SEPARATOR.'phpunit'];

$exitCode = 0;
$ownedCandidates = [$dbReal, $metaPath, $dbReal.'-wal', $dbReal.'-shm', $dbReal.'-journal'];

try {
    fwrite(STDOUT, "Running migrations against disposable database only (isolated artisan)...\n");
    $migrate = EQUITY_PHASE4C3_run([$php, $isolatedArtisan, 'migrate', '--force', '--no-interaction'], $childEnv, $repoReal);
    fwrite(STDOUT, $migrate['output']);
    if ($migrate['code'] !== 0) {
        fwrite(STDERR, "FAIL migrate (exit {$migrate['code']})\n");
        $exitCode = $migrate['code'] ?: 1;
    } else {
        fwrite(STDOUT, "Seeding CurrencySeeder against disposable database only (isolated artisan)...\n");
        $seed = EQUITY_PHASE4C3_run([$php, $isolatedArtisan, 'db:seed', '--class=CurrencySeeder', '--force', '--no-interaction'], $childEnv, $repoReal);
        fwrite(STDOUT, $seed['output']);
        if ($seed['code'] !== 0) {
            fwrite(STDERR, "FAIL seed (exit {$seed['code']})\n");
            $exitCode = $seed['code'] ?: 1;
        } else {
            fwrite(STDOUT, "Running Phase 4C.3 PHPUnit concurrency suite...\n");
            $test = EQUITY_PHASE4C3_run(
                array_merge($phpunitCmd, [
                    '--configuration',
                    $repoReal.DIRECTORY_SEPARATOR.'phpunit.phase4c3-sqlite-concurrency.xml',
                    '--colors=never',
                ]),
                $childEnv,
                $repoReal,
            );
            fwrite(STDOUT, $test['output']);
            if ($test['code'] !== 0) {
                fwrite(STDERR, "FAIL Phase 4C.3 tests (exit {$test['code']})\n");
                $exitCode = $test['code'];
            } else {
                fwrite(STDOUT, "PASS Phase 4C.3 runner\n");
            }
        }
    }
} finally {
    $cleanupFiles = EQUITY_PHASE4C3_cleanup_files($ownedCandidates, $token, $dbBasename, $tempReal, $repoReal, $forbiddenReal);
    $cleanupWork = EQUITY_PHASE4C3_cleanup_workdir($workReal, $token, $tempReal, $repoReal);
    $deleted = array_merge($cleanupFiles['deleted'], $cleanupWork['deleted']);
    $left = array_merge($cleanupFiles['left'], $cleanupWork['left']);
    $errors = array_merge($cleanupFiles['errors'], $cleanupWork['errors']);

    fwrite(STDOUT, 'Cleanup deleted: '.implode(', ', $deleted !== [] ? $deleted : ['(none)'])."\n");
    if ($left !== []) {
        fwrite(STDOUT, 'Cleanup left in place: '.implode(', ', $left)."\n");
    }
    if ($errors !== []) {
        fwrite(STDERR, 'Cleanup failures: '.implode(' | ', $errors)."\n");
        if ($exitCode === 0) {
            $exitCode = 3;
        }
    }
}

exit($exitCode);
