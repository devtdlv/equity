<?php

namespace Tests\Integration\Concurrency;

use RuntimeException;
use Tests\Integration\SqliteFile\SqliteFileIsolation;

/**
 * Phase 3 file-SQLite isolation. Mirrors Phase 2 validation with equity-phase3- prefix.
 */
final class Phase3Isolation
{
    public const TOKEN_HEX_LENGTH = SqliteFileIsolation::TOKEN_HEX_LENGTH;

    public const DATABASE_PREFIX = 'equity-phase3-';

    public const ENV_SQLITE_PATH = 'EQUITY_PHASE3_SQLITE_PATH';

    public const ENV_TOKEN = 'EQUITY_PHASE3_TOKEN';

    public const ENV_REPO_ROOT = 'EQUITY_PHASE3_REPO_ROOT';

    public const ENV_FORBIDDEN_DB = 'EQUITY_PHASE3_FORBIDDEN_DB';

    public const ENV_WORKDIR = 'EQUITY_PHASE3_WORKDIR';

    private static ?string $approvedDatabasePath = null;

    private static ?string $repositoryRoot = null;

    private static ?string $forbiddenDatabasePath = null;

    private static ?string $runToken = null;

    private static ?string $workDir = null;

    public static function databaseBasenameForToken(string $token): string
    {
        return self::DATABASE_PREFIX.$token.'.sqlite';
    }

    /**
     * @param  array<string, string|null>  $env
     * @return array{
     *     ok: bool,
     *     errors: list<string>,
     *     token: ?string,
     *     approved_database_path: ?string,
     *     repository_root: ?string,
     *     forbidden_database_path: ?string,
     *     workdir: ?string
     * }
     */
    public static function validate(array $env, string $bootstrapDerivedRepoRoot): array
    {
        $mapped = [
            SqliteFileIsolation::ENV_TOKEN => $env[self::ENV_TOKEN] ?? null,
            SqliteFileIsolation::ENV_SQLITE_PATH => $env[self::ENV_SQLITE_PATH] ?? null,
            SqliteFileIsolation::ENV_REPO_ROOT => $env[self::ENV_REPO_ROOT] ?? null,
            SqliteFileIsolation::ENV_FORBIDDEN_DB => $env[self::ENV_FORBIDDEN_DB] ?? null,
            'DB_DATABASE' => $env['DB_DATABASE'] ?? null,
        ];

        // Reuse Phase 2 path/token/forbidden/config-cache rules by temporarily expecting phase3 basename.
        $token = is_string($mapped[SqliteFileIsolation::ENV_TOKEN] ?? null) ? $mapped[SqliteFileIsolation::ENV_TOKEN] : null;
        $path = is_string($mapped[SqliteFileIsolation::ENV_SQLITE_PATH] ?? null) ? $mapped[SqliteFileIsolation::ENV_SQLITE_PATH] : null;

        $errors = [];
        $derivedRepo = realpath($bootstrapDerivedRepoRoot);
        if ($derivedRepo === false) {
            return self::invalid(['Bootstrap-derived repository root could not be resolved.']);
        }
        $derivedRepo = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $derivedRepo);

        if ($token === null || $token === '') {
            $errors[] = 'Phase 3 run token is missing.';
        } elseif (! SqliteFileIsolation::isValidToken($token)) {
            $errors[] = 'Phase 3 run token must be a '.self::TOKEN_HEX_LENGTH.'-character hexadecimal string.';
            $token = null;
        }

        if ($path === null || $path === '') {
            $errors[] = 'Phase 3 disposable SQLite path is missing.';
        }

        $claimedRepo = is_string($env[self::ENV_REPO_ROOT] ?? null) ? $env[self::ENV_REPO_ROOT] : null;
        if ($claimedRepo === null || $claimedRepo === '') {
            $errors[] = 'Phase 3 repository root env is missing.';
        }

        $forbidden = is_string($env[self::ENV_FORBIDDEN_DB] ?? null) ? $env[self::ENV_FORBIDDEN_DB] : null;
        if ($forbidden === null || $forbidden === '') {
            $errors[] = 'Phase 3 forbidden database path env is missing.';
        }

        $workdir = is_string($env[self::ENV_WORKDIR] ?? null) ? $env[self::ENV_WORKDIR] : null;
        if ($workdir === null || $workdir === '') {
            $errors[] = 'Phase 3 workdir env is missing.';
        }

        if ($errors !== [] || $token === null || $path === null || $claimedRepo === null || $forbidden === null || $workdir === null) {
            return self::invalid($errors !== [] ? $errors : ['Phase 3 isolation env is incomplete.']);
        }

        $expectedBasename = self::databaseBasenameForToken($token);
        if (basename(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path)) !== $expectedBasename) {
            $errors[] = 'Disposable database basename must be exactly '.$expectedBasename.'.';
        }

        $resolvedPath = realpath($path);
        if ($resolvedPath === false || ! is_file($resolvedPath) || is_link($path)) {
            $errors[] = 'Disposable database path must resolve to an existing regular file.';
            $resolvedPath = null;
        } else {
            $resolvedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $resolvedPath);
            if (basename($resolvedPath) !== $expectedBasename) {
                $errors[] = 'Resolved disposable database basename does not match the run token.';
            }
        }

        $resolvedClaimedRepo = realpath($claimedRepo);
        if ($resolvedClaimedRepo === false) {
            $errors[] = 'Claimed repository root could not be resolved.';
        } else {
            $resolvedClaimedRepo = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $resolvedClaimedRepo);
            if ($resolvedClaimedRepo !== $derivedRepo) {
                $errors[] = 'Claimed repository root does not match the bootstrap-derived project root.';
            }
        }

        $expectedForbidden = $derivedRepo.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite';
        $forbiddenReal = realpath($forbidden);
        $expectedForbiddenReal = realpath($expectedForbidden);
        if ($forbiddenReal !== false && $expectedForbiddenReal !== false) {
            $resolvedForbidden = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $forbiddenReal);
            if ($resolvedForbidden !== str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $expectedForbiddenReal)) {
                $errors[] = 'Forbidden database path does not match the project database/database.sqlite.';
            }
        } elseif ($forbiddenReal === false && $expectedForbiddenReal === false) {
            $resolvedForbidden = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $forbidden);
            if ($resolvedForbidden !== str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $expectedForbidden)) {
                $errors[] = 'Forbidden database path does not match the project database/database.sqlite.';
            }
        } else {
            $errors[] = 'Forbidden database path does not match the project database/database.sqlite.';
            $resolvedForbidden = $forbiddenReal !== false
                ? str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $forbiddenReal)
                : str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $expectedForbidden);
        }

        $resolvedWorkdir = realpath($workdir);
        if ($resolvedWorkdir === false || ! is_dir($resolvedWorkdir)) {
            $errors[] = 'Phase 3 workdir must resolve to an existing directory.';
            $resolvedWorkdir = null;
        } else {
            $resolvedWorkdir = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $resolvedWorkdir);
            if (str_starts_with($resolvedWorkdir, $derivedRepo.DIRECTORY_SEPARATOR)) {
                $errors[] = 'Phase 3 workdir must not live inside the repository.';
            }
            if (! str_contains(basename($resolvedWorkdir), $token)) {
                $errors[] = 'Phase 3 workdir basename must contain the run token.';
            }
        }

        if ($resolvedPath !== null) {
            if (str_starts_with($resolvedPath, $derivedRepo.DIRECTORY_SEPARATOR)) {
                $errors[] = 'Phase 3 disposable database must not live inside the repository.';
            }
            if ($resolvedPath === ($resolvedForbidden ?? null)) {
                $errors[] = 'Phase 3 disposable database path collides with the protected application database.';
            }
        }

        $configCache = $derivedRepo.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'config.php';
        if (is_file($configCache)) {
            $errors[] = 'Laravel config cache is present at bootstrap/cache/config.php. Refuse to boot.';
        }

        $dbDatabase = is_string($env['DB_DATABASE'] ?? null) ? $env['DB_DATABASE'] : null;
        if ($resolvedPath !== null) {
            if ($dbDatabase === null || $dbDatabase === '') {
                $errors[] = 'DB_DATABASE must be set to the approved disposable database path.';
            } else {
                $resolvedDbDatabase = realpath($dbDatabase);
                if ($resolvedDbDatabase === false
                    || str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $resolvedDbDatabase) !== $resolvedPath) {
                    $errors[] = 'DB_DATABASE does not match the approved disposable database path.';
                }
            }
        }

        if ($errors !== []) {
            return self::invalid($errors);
        }

        return [
            'ok' => true,
            'errors' => [],
            'token' => $token,
            'approved_database_path' => $resolvedPath,
            'repository_root' => $derivedRepo,
            'forbidden_database_path' => $resolvedForbidden ?? str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $expectedForbidden),
            'workdir' => $resolvedWorkdir,
        ];
    }

    public static function bootFromEnvironment(string $bootstrapDerivedRepoRoot): void
    {
        $env = [
            self::ENV_SQLITE_PATH => self::env(self::ENV_SQLITE_PATH),
            self::ENV_TOKEN => self::env(self::ENV_TOKEN),
            self::ENV_REPO_ROOT => self::env(self::ENV_REPO_ROOT),
            self::ENV_FORBIDDEN_DB => self::env(self::ENV_FORBIDDEN_DB),
            self::ENV_WORKDIR => self::env(self::ENV_WORKDIR),
            'DB_DATABASE' => self::env('DB_DATABASE'),
        ];

        $result = self::validate($env, $bootstrapDerivedRepoRoot);
        if ($result['ok'] !== true) {
            throw new RuntimeException(
                "Phase 3 SQLite concurrency isolation refused to boot.\n".implode("\n", $result['errors']),
            );
        }

        $normalizedPath = $result['approved_database_path'];
        $normalizedRepo = $result['repository_root'];
        $normalizedForbidden = $result['forbidden_database_path'];
        $token = $result['token'];
        $workdir = $result['workdir'];

        if ($normalizedPath === null || $normalizedRepo === null || $normalizedForbidden === null || $token === null || $workdir === null) {
            throw new RuntimeException('Phase 3 isolation validation returned an incomplete success snapshot.');
        }

        SqliteFileIsolation::forceEnv([
            'APP_ENV' => 'testing',
            'APP_MAINTENANCE_DRIVER' => 'file',
            'BCRYPT_ROUNDS' => '4',
            'CACHE_STORE' => 'array',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $normalizedPath,
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
            'APP_CONFIG_CACHE' => $normalizedRepo.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'phpunit-config-must-not-exist.php',
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'PULSE_ENABLED' => 'false',
            'TELESCOPE_ENABLED' => 'false',
            self::ENV_SQLITE_PATH => $normalizedPath,
            self::ENV_TOKEN => $token,
            self::ENV_REPO_ROOT => $normalizedRepo,
            self::ENV_FORBIDDEN_DB => $normalizedForbidden,
            self::ENV_WORKDIR => $workdir,
            // Keep Phase 2 env empty so Phase 2 bootstrap cannot accidentally activate.
            SqliteFileIsolation::ENV_SQLITE_PATH => '',
            SqliteFileIsolation::ENV_TOKEN => '',
            SqliteFileIsolation::ENV_REPO_ROOT => '',
            SqliteFileIsolation::ENV_FORBIDDEN_DB => '',
        ]);

        $forcedDb = self::env('DB_DATABASE');
        if ($forcedDb === null || realpath($forcedDb) === false
            || str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) realpath($forcedDb)) !== $normalizedPath) {
            throw new RuntimeException('DB_DATABASE could not be forced to the approved disposable database path.');
        }

        if (is_file($normalizedRepo.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'config.php')) {
            throw new RuntimeException('Laravel config cache appeared before boot; refusing to continue.');
        }

        self::$approvedDatabasePath = $normalizedPath;
        self::$repositoryRoot = $normalizedRepo;
        self::$forbiddenDatabasePath = $normalizedForbidden;
        self::$runToken = $token;
        self::$workDir = $workdir;
    }

    public static function approvedDatabasePath(): string
    {
        return self::$approvedDatabasePath ?? throw new RuntimeException('Phase 3 isolation has not been booted.');
    }

    public static function repositoryRoot(): string
    {
        return self::$repositoryRoot ?? throw new RuntimeException('Phase 3 isolation has not been booted.');
    }

    public static function forbiddenDatabasePath(): string
    {
        return self::$forbiddenDatabasePath ?? throw new RuntimeException('Phase 3 isolation has not been booted.');
    }

    public static function runToken(): string
    {
        return self::$runToken ?? throw new RuntimeException('Phase 3 isolation has not been booted.');
    }

    public static function workDir(): string
    {
        return self::$workDir ?? throw new RuntimeException('Phase 3 isolation has not been booted.');
    }

    private static function env(string $key): ?string
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    /**
     * @param  list<string>  $errors
     * @return array{
     *     ok: bool,
     *     errors: list<string>,
     *     token: null,
     *     approved_database_path: null,
     *     repository_root: null,
     *     forbidden_database_path: null,
     *     workdir: null
     * }
     */
    private static function invalid(array $errors): array
    {
        return [
            'ok' => false,
            'errors' => $errors,
            'token' => null,
            'approved_database_path' => null,
            'repository_root' => null,
            'forbidden_database_path' => null,
            'workdir' => null,
        ];
    }
}
