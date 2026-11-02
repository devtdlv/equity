<?php

namespace Tests\Integration\SqliteFile;

use RuntimeException;

/**
 * Process-level Phase 2 isolation state. Populated only by the Phase 2 bootstrap.
 *
 * Validation is pure and may be unit-tested without opening any database.
 */
final class SqliteFileIsolation
{
    public const TOKEN_HEX_LENGTH = 32;

    public const ENV_SQLITE_PATH = 'EQUITY_PHASE2_SQLITE_PATH';

    public const ENV_TOKEN = 'EQUITY_PHASE2_TOKEN';

    public const ENV_REPO_ROOT = 'EQUITY_PHASE2_REPO_ROOT';

    public const ENV_FORBIDDEN_DB = 'EQUITY_PHASE2_FORBIDDEN_DB';

    private static ?string $approvedDatabasePath = null;

    private static ?string $repositoryRoot = null;

    private static ?string $forbiddenDatabasePath = null;

    private static ?string $runToken = null;

    /**
     * @param  array<string, string|null>  $env
     * @return array{
     *     ok: bool,
     *     errors: list<string>,
     *     token: ?string,
     *     approved_database_path: ?string,
     *     repository_root: ?string,
     *     forbidden_database_path: ?string
     * }
     */
    public static function validate(array $env, string $bootstrapDerivedRepoRoot): array
    {
        $errors = [];

        $derivedRepo = self::normalizeExistingPath($bootstrapDerivedRepoRoot);
        if ($derivedRepo === null) {
            return self::invalid(['Bootstrap-derived repository root could not be resolved.']);
        }

        $token = self::stringOrNull($env[self::ENV_TOKEN] ?? null);
        if ($token === null) {
            $errors[] = 'Phase 2 run token is missing.';
        } elseif (! self::isValidToken($token)) {
            $errors[] = 'Phase 2 run token must be a '.self::TOKEN_HEX_LENGTH.'-character hexadecimal string.';
            $token = null;
        }

        $path = self::stringOrNull($env[self::ENV_SQLITE_PATH] ?? null);
        if ($path === null) {
            $errors[] = 'Phase 2 disposable SQLite path is missing.';
        }

        $claimedRepo = self::stringOrNull($env[self::ENV_REPO_ROOT] ?? null);
        if ($claimedRepo === null) {
            $errors[] = 'Phase 2 repository root env is missing.';
        }

        $forbidden = self::stringOrNull($env[self::ENV_FORBIDDEN_DB] ?? null);
        if ($forbidden === null) {
            $errors[] = 'Phase 2 forbidden database path env is missing.';
        }

        if ($errors !== []) {
            return self::invalid($errors);
        }

        /** @var string $token */
        /** @var string $path */
        /** @var string $claimedRepo */
        /** @var string $forbidden */

        $expectedBasename = self::databaseBasenameForToken($token);
        $providedBasename = basename(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path));
        if ($providedBasename !== $expectedBasename) {
            $errors[] = 'Disposable database basename must be exactly '.$expectedBasename.'.';
        }

        $resolvedPath = self::normalizeExistingPath($path);
        if ($resolvedPath === null || ! is_file($resolvedPath) || is_link($path)) {
            $errors[] = 'Disposable database path must resolve to an existing regular file.';
            $resolvedPath = null;
        } elseif (is_link($resolvedPath)) {
            $errors[] = 'Disposable database path must not be a symbolic link.';
            $resolvedPath = null;
        } elseif (basename($resolvedPath) !== $expectedBasename) {
            $errors[] = 'Resolved disposable database basename does not match the run token.';
        }

        $resolvedClaimedRepo = self::normalizeExistingPath($claimedRepo);
        if ($resolvedClaimedRepo === null) {
            $errors[] = 'Claimed repository root could not be resolved.';
        } elseif ($resolvedClaimedRepo !== $derivedRepo) {
            $errors[] = 'Claimed repository root does not match the bootstrap-derived project root.';
        }

        $expectedForbidden = $derivedRepo.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite';
        $normalizedForbidden = self::normalizePathString($forbidden);
        $forbiddenReal = self::normalizeExistingPath($forbidden);
        $expectedForbiddenReal = self::normalizeExistingPath($expectedForbidden);

        if ($forbiddenReal !== null && $expectedForbiddenReal !== null) {
            if ($forbiddenReal !== $expectedForbiddenReal) {
                $errors[] = 'Forbidden database path does not match the project database/database.sqlite.';
            }
            $resolvedForbidden = $forbiddenReal;
        } elseif ($forbiddenReal === null && $expectedForbiddenReal === null) {
            if ($normalizedForbidden !== self::normalizePathString($expectedForbidden)) {
                $errors[] = 'Forbidden database path does not match the project database/database.sqlite.';
            }
            $resolvedForbidden = self::normalizePathString($expectedForbidden);
        } else {
            $errors[] = 'Forbidden database path does not match the project database/database.sqlite.';
            $resolvedForbidden = $forbiddenReal ?? self::normalizePathString($expectedForbidden);
        }

        if ($resolvedPath !== null) {
            if (str_starts_with($resolvedPath, $derivedRepo.DIRECTORY_SEPARATOR)) {
                $errors[] = 'Phase 2 disposable database must not live inside the repository.';
            }
            if ($resolvedPath === $resolvedForbidden) {
                $errors[] = 'Phase 2 disposable database path collides with the protected application database.';
            }
        }

        $configCache = $derivedRepo.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'config.php';
        if (is_file($configCache)) {
            $errors[] = 'Laravel config cache is present at bootstrap/cache/config.php. Refuse to boot.';
        }

        $dbDatabase = self::stringOrNull($env['DB_DATABASE'] ?? null);
        if ($resolvedPath !== null) {
            if ($dbDatabase === null) {
                $errors[] = 'DB_DATABASE must be set to the approved disposable database path.';
            } else {
                $resolvedDbDatabase = self::normalizeExistingPath($dbDatabase);
                if ($resolvedDbDatabase === null || $resolvedDbDatabase !== $resolvedPath) {
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
            'forbidden_database_path' => $resolvedForbidden,
        ];
    }

    public static function isValidToken(string $token): bool
    {
        return strlen($token) === self::TOKEN_HEX_LENGTH
            && ctype_xdigit($token)
            && $token === strtolower($token);
    }

    public static function databaseBasenameForToken(string $token): string
    {
        return 'equity-phase2-'.$token.'.sqlite';
    }

    /**
     * Boot isolation from process environment. Call only from Phase 2 bootstrap.
     */
    public static function bootFromEnvironment(string $bootstrapDerivedRepoRoot): void
    {
        $env = [
            self::ENV_SQLITE_PATH => self::env(self::ENV_SQLITE_PATH),
            self::ENV_TOKEN => self::env(self::ENV_TOKEN),
            self::ENV_REPO_ROOT => self::env(self::ENV_REPO_ROOT),
            self::ENV_FORBIDDEN_DB => self::env(self::ENV_FORBIDDEN_DB),
            'DB_DATABASE' => self::env('DB_DATABASE'),
        ];

        $result = self::validate($env, $bootstrapDerivedRepoRoot);
        if ($result['ok'] !== true) {
            throw new RuntimeException(
                "Phase 2 SQLite file isolation refused to boot.\n".implode("\n", $result['errors']),
            );
        }

        $normalizedPath = $result['approved_database_path'];
        $normalizedRepo = $result['repository_root'];
        $normalizedForbidden = $result['forbidden_database_path'];
        $token = $result['token'];

        if ($normalizedPath === null || $normalizedRepo === null || $normalizedForbidden === null || $token === null) {
            throw new RuntimeException('Phase 2 isolation validation returned an incomplete success snapshot.');
        }

        self::forceEnv([
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
        ]);

        // Re-check after forcing env: DB_DATABASE must still match.
        $forcedDb = self::env('DB_DATABASE');
        if ($forcedDb === null || self::normalizeExistingPath($forcedDb) !== $normalizedPath) {
            throw new RuntimeException('DB_DATABASE could not be forced to the approved disposable database path.');
        }

        if (is_file($normalizedRepo.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'config.php')) {
            throw new RuntimeException('Laravel config cache appeared before boot; refusing to continue.');
        }

        self::$approvedDatabasePath = $normalizedPath;
        self::$repositoryRoot = $normalizedRepo;
        self::$forbiddenDatabasePath = $normalizedForbidden;
        self::$runToken = $token;
    }

    public static function approvedDatabasePath(): string
    {
        return self::$approvedDatabasePath
            ?? throw new RuntimeException('Phase 2 isolation has not been booted.');
    }

    public static function repositoryRoot(): string
    {
        return self::$repositoryRoot
            ?? throw new RuntimeException('Phase 2 isolation has not been booted.');
    }

    public static function forbiddenDatabasePath(): string
    {
        return self::$forbiddenDatabasePath
            ?? throw new RuntimeException('Phase 2 isolation has not been booted.');
    }

    public static function runToken(): string
    {
        return self::$runToken
            ?? throw new RuntimeException('Phase 2 isolation has not been booted.');
    }

    /**
     * @param  array<string, string>  $values
     */
    public static function forceEnv(array $values): void
    {
        foreach ($values as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    private static function env(string $key): ?string
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    private static function normalizeExistingPath(string $path): ?string
    {
        $real = realpath($path);
        if ($real === false) {
            return null;
        }

        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $real);
    }

    private static function normalizePathString(string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    /**
     * @param  list<string>  $errors
     * @return array{
     *     ok: bool,
     *     errors: list<string>,
     *     token: null,
     *     approved_database_path: null,
     *     repository_root: null,
     *     forbidden_database_path: null
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
        ];
    }
}
