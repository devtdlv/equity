<?php

namespace Tests\Integration\Phase4C3;

use Illuminate\Foundation\Application;
use RuntimeException;
use Tests\Integration\SqliteFile\SqliteFileIsolation;

/**
 * Phase 4C.3 file-SQLite isolation. Mirrors Phase 2 validation with equity-phase4c3- prefix.
 *
 * Also owns fail-closed redirection of Laravel's Dotenv load away from the repository `.env`.
 */
final class Phase4C3Isolation
{
    public const TOKEN_HEX_LENGTH = SqliteFileIsolation::TOKEN_HEX_LENGTH;

    public const DATABASE_PREFIX = 'equity-phase4c3-';

    public const ENV_SQLITE_PATH = 'EQUITY_PHASE4C3_SQLITE_PATH';

    public const ENV_TOKEN = 'EQUITY_PHASE4C3_TOKEN';

    public const ENV_REPO_ROOT = 'EQUITY_PHASE4C3_REPO_ROOT';

    public const ENV_FORBIDDEN_DB = 'EQUITY_PHASE4C3_FORBIDDEN_DB';

    public const ENV_WORKDIR = 'EQUITY_PHASE4C3_WORKDIR';

    public const ENV_ENV_FILE = 'EQUITY_PHASE4C3_ENV_FILE';

    private static ?string $approvedDatabasePath = null;

    private static ?string $repositoryRoot = null;

    private static ?string $forbiddenDatabasePath = null;

    private static ?string $runToken = null;

    private static ?string $workDir = null;

    private static ?string $approvedEnvironmentFilePath = null;

    public static function databaseBasenameForToken(string $token): string
    {
        return self::DATABASE_PREFIX.$token.'.sqlite';
    }

    public static function environmentBasenameForToken(string $token): string
    {
        return self::DATABASE_PREFIX.$token.'.env';
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
     *     workdir: ?string,
     *     approved_environment_file_path: ?string
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
            $errors[] = 'Phase 4C.3 run token is missing.';
        } elseif (! SqliteFileIsolation::isValidToken($token)) {
            $errors[] = 'Phase 4C.3 run token must be a '.self::TOKEN_HEX_LENGTH.'-character hexadecimal string.';
            $token = null;
        }

        if ($path === null || $path === '') {
            $errors[] = 'Phase 4C.3 disposable SQLite path is missing.';
        }

        $claimedRepo = is_string($env[self::ENV_REPO_ROOT] ?? null) ? $env[self::ENV_REPO_ROOT] : null;
        if ($claimedRepo === null || $claimedRepo === '') {
            $errors[] = 'Phase 4C.3 repository root env is missing.';
        }

        $forbidden = is_string($env[self::ENV_FORBIDDEN_DB] ?? null) ? $env[self::ENV_FORBIDDEN_DB] : null;
        if ($forbidden === null || $forbidden === '') {
            $errors[] = 'Phase 4C.3 forbidden database path env is missing.';
        }

        $workdir = is_string($env[self::ENV_WORKDIR] ?? null) ? $env[self::ENV_WORKDIR] : null;
        if ($workdir === null || $workdir === '') {
            $errors[] = 'Phase 4C.3 workdir env is missing.';
        }

        $envFile = is_string($env[self::ENV_ENV_FILE] ?? null) ? $env[self::ENV_ENV_FILE] : null;
        if ($envFile === null || $envFile === '') {
            $errors[] = 'Phase 4C.3 disposable environment file path is missing.';
        }

        if ($errors !== [] || $token === null || $path === null || $claimedRepo === null || $forbidden === null || $workdir === null || $envFile === null) {
            return self::invalid($errors !== [] ? $errors : ['Phase 4C.3 isolation env is incomplete.']);
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
            $errors[] = 'Phase 4C.3 workdir must resolve to an existing directory.';
            $resolvedWorkdir = null;
        } else {
            $resolvedWorkdir = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $resolvedWorkdir);
            if (str_starts_with($resolvedWorkdir, $derivedRepo.DIRECTORY_SEPARATOR)) {
                $errors[] = 'Phase 4C.3 workdir must not live inside the repository.';
            }
            if (! str_contains(basename($resolvedWorkdir), $token)) {
                $errors[] = 'Phase 4C.3 workdir basename must contain the run token.';
            }
        }

        if ($resolvedPath !== null) {
            if (str_starts_with($resolvedPath, $derivedRepo.DIRECTORY_SEPARATOR)) {
                $errors[] = 'Phase 4C.3 disposable database must not live inside the repository.';
            }
            if ($resolvedPath === ($resolvedForbidden ?? null)) {
                $errors[] = 'Phase 4C.3 disposable database path collides with the protected application database.';
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

        $appEnv = is_string($env['APP_ENV'] ?? null) && $env['APP_ENV'] !== ''
            ? $env['APP_ENV']
            : 'testing';

        $envRedirect = self::validateEnvironmentRedirect([
            'token' => $token,
            'repository_root' => $derivedRepo,
            'workdir' => $workdir,
            'environment_file' => $envFile,
            'app_env' => $appEnv,
            'protected_dotenv' => $derivedRepo.DIRECTORY_SEPARATOR.'.env',
        ]);
        if ($envRedirect['ok'] !== true) {
            $errors = array_merge($errors, $envRedirect['errors']);
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
            'approved_environment_file_path' => $envRedirect['base_environment_file_path'],
        ];
    }

    /**
     * Pure validation of the disposable Laravel environment-file redirect.
     *
     * Does not open the repository `.env`. Synthetic fixtures may supply a fake
     * protected_dotenv path that is never read — only compared by normalized path.
     *
     * @param  array{
     *     token?: string|null,
     *     repository_root?: string|null,
     *     workdir?: string|null,
     *     environment_file?: string|null,
     *     app_env?: string|null,
     *     protected_dotenv?: string|null
     * }  $snapshot
     * @return array{
     *     ok: bool,
     *     errors: list<string>,
     *     environment_path: ?string,
     *     environment_file: ?string,
     *     base_environment_file_path: ?string,
     *     resolved_load_path: ?string,
     *     selected_via_app_env_suffix: bool
     * }
     */
    public static function validateEnvironmentRedirect(array $snapshot): array
    {
        $errors = [];
        $token = is_string($snapshot['token'] ?? null) ? $snapshot['token'] : null;
        $repo = is_string($snapshot['repository_root'] ?? null) ? $snapshot['repository_root'] : null;
        $workdir = is_string($snapshot['workdir'] ?? null) ? $snapshot['workdir'] : null;
        $envFile = is_string($snapshot['environment_file'] ?? null) ? $snapshot['environment_file'] : null;
        $appEnv = is_string($snapshot['app_env'] ?? null) && $snapshot['app_env'] !== ''
            ? $snapshot['app_env']
            : 'testing';
        $protectedLogical = is_string($snapshot['protected_dotenv'] ?? null) && $snapshot['protected_dotenv'] !== ''
            ? str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $snapshot['protected_dotenv'])
            : null;

        if ($token === null || $token === '' || ! SqliteFileIsolation::isValidToken($token)) {
            $errors[] = 'Environment redirect requires a valid Phase 4C.3 ownership token.';
            $token = null;
        }
        if ($repo === null || $repo === '') {
            $errors[] = 'Environment redirect requires a repository root.';
        }
        if ($workdir === null || $workdir === '') {
            $errors[] = 'Environment redirect requires a disposable workdir.';
        }
        if ($envFile === null || $envFile === '') {
            $errors[] = 'Environment redirect requires a disposable environment file path.';
        }

        if ($errors !== [] || $token === null || $repo === null || $workdir === null || $envFile === null) {
            return self::invalidEnvironmentRedirect($errors !== [] ? $errors : ['Environment redirect snapshot is incomplete.']);
        }

        $normalizedEnvClaim = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $envFile);
        $envBasename = basename($normalizedEnvClaim);
        if ($envBasename === '.env' || strcasecmp($envBasename, '.env') === 0) {
            $errors[] = 'Protected .env basename is rejected for disposable environment redirection.';
        }

        $expectedBasename = self::environmentBasenameForToken($token);
        if ($envBasename !== $expectedBasename) {
            $errors[] = 'Disposable environment basename must be exactly '.$expectedBasename.'.';
        }
        if (! str_contains($envBasename, $token)) {
            $errors[] = 'Disposable environment basename must contain the ownership token.';
        }

        // Reject path traversal / absolute escapes before realpath (realpath collapses ..).
        if (str_contains($normalizedEnvClaim, '..')) {
            $errors[] = 'Disposable environment path must not contain path traversal segments.';
        }

        $resolvedRepo = realpath($repo);
        if ($resolvedRepo === false) {
            $errors[] = 'Environment redirect repository root could not be resolved.';
            $resolvedRepo = null;
        } else {
            $resolvedRepo = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $resolvedRepo);
        }

        $resolvedWorkdir = realpath($workdir);
        if ($resolvedWorkdir === false || ! is_dir($resolvedWorkdir)) {
            $errors[] = 'Environment redirect workdir must resolve to an existing directory.';
            $resolvedWorkdir = null;
        } else {
            $resolvedWorkdir = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $resolvedWorkdir);
            if ($resolvedRepo !== null && str_starts_with($resolvedWorkdir, $resolvedRepo.DIRECTORY_SEPARATOR)) {
                $errors[] = 'Environment redirect workdir must not live inside the repository.';
            }
            if (! str_contains(basename($resolvedWorkdir), $token)) {
                $errors[] = 'Environment redirect workdir basename must contain the ownership token.';
            }
        }

        $resolvedEnv = realpath($envFile);
        if ($resolvedEnv === false || ! is_file($resolvedEnv) || is_link($envFile)) {
            $errors[] = 'Disposable environment file must resolve to an existing regular file.';
            $resolvedEnv = null;
        } else {
            $resolvedEnv = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $resolvedEnv);
            if (basename($resolvedEnv) !== $expectedBasename) {
                $errors[] = 'Resolved disposable environment basename does not match the ownership token.';
            }
            if ($resolvedWorkdir !== null && dirname($resolvedEnv) !== $resolvedWorkdir) {
                $errors[] = 'Disposable environment file must live directly inside the owned workdir.';
            }
            if ($resolvedRepo !== null && str_starts_with($resolvedEnv, $resolvedRepo.DIRECTORY_SEPARATOR)) {
                $errors[] = 'Disposable environment file must not live inside the repository.';
            }
        }

        // Compare against protected path without opening/reading it.
        $protectedResolved = null;
        if ($protectedLogical !== null) {
            $protectedReal = realpath($protectedLogical);
            $protectedResolved = $protectedReal !== false
                ? str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $protectedReal)
                : $protectedLogical;
            if ($resolvedEnv !== null && $resolvedEnv === $protectedResolved) {
                $errors[] = 'Disposable environment file collides with the protected repository .env.';
            }
            if (strcasecmp(basename($protectedLogical), '.env') !== 0) {
                $errors[] = 'Protected dotenv path must be named .env for isolation comparison.';
            }
        } elseif ($resolvedRepo !== null) {
            $protectedLogical = $resolvedRepo.DIRECTORY_SEPARATOR.'.env';
            $protectedReal = realpath($protectedLogical);
            $protectedResolved = $protectedReal !== false
                ? str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $protectedReal)
                : $protectedLogical;
            if ($resolvedEnv !== null && $resolvedEnv === $protectedResolved) {
                $errors[] = 'Disposable environment file collides with the protected repository .env.';
            }
        }

        // Mirror LoadEnvironmentVariables::checkForSpecificEnvironmentFile APP_ENV selection.
        // Redirect always points at the base disposable basename; Laravel may then switch to
        // "{base}.{APP_ENV}" when that file exists in the same workdir.
        // Symlinks and path-like APP_ENV values are rejected before Laravel can follow them.
        $selectedViaSuffix = false;
        $loadPath = $resolvedEnv;
        if ($resolvedWorkdir !== null && $token !== null && $resolvedEnv !== null) {
            if (str_contains($appEnv, '..')
                || str_contains($appEnv, '/')
                || str_contains($appEnv, '\\')
                || str_contains($appEnv, DIRECTORY_SEPARATOR)) {
                $errors[] = 'APP_ENV value must not contain path separators or traversal segments.';
            } else {
                $suffixedBasename = $expectedBasename.'.'.$appEnv;
                $suffixedPath = $resolvedWorkdir.DIRECTORY_SEPARATOR.$suffixedBasename;
                if (is_link($suffixedPath)) {
                    $errors[] = 'APP_ENV-suffixed environment file must not be a symlink.';
                } elseif (is_file($suffixedPath)) {
                    $suffixedReal = realpath($suffixedPath);
                    if ($suffixedReal === false) {
                        $errors[] = 'APP_ENV-suffixed environment file could not be resolved.';
                    } else {
                        $suffixedReal = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $suffixedReal);
                        if (dirname($suffixedReal) !== $resolvedWorkdir) {
                            $errors[] = 'APP_ENV-suffixed environment file escaped the owned workdir.';
                        }
                        if ($protectedResolved !== null && $suffixedReal === $protectedResolved) {
                            $errors[] = 'APP_ENV-suffixed environment file collides with the protected repository .env.';
                        }
                        // Hardlinks share an inode but can disagree on realpath; reject before Laravel loads.
                        if ($protectedLogical !== null
                            && is_file($protectedLogical)
                            && ! is_link($protectedLogical)
                            && @fileinode($suffixedPath) !== false
                            && @fileinode($protectedLogical) !== false
                            && fileinode($suffixedPath) === fileinode($protectedLogical)) {
                            $errors[] = 'APP_ENV-suffixed environment file collides with the protected repository .env.';
                        }
                        if (basename($suffixedReal) === '.env' || strcasecmp(basename($suffixedReal), '.env') === 0) {
                            $errors[] = 'APP_ENV selection resolved to a protected .env basename.';
                        }
                        if (! str_contains(basename($suffixedReal), $token)) {
                            $errors[] = 'APP_ENV-suffixed environment basename must contain the ownership token.';
                        }
                        if ($errors === []) {
                            $selectedViaSuffix = true;
                            $loadPath = $suffixedReal;
                        }
                    }
                }
            }
        }

        if ($loadPath !== null && $protectedResolved !== null && $loadPath === $protectedResolved) {
            $errors[] = 'Resolved Laravel environment file must not be the protected repository .env.';
        }
        if ($loadPath !== null && (basename($loadPath) === '.env' || strcasecmp(basename($loadPath), '.env') === 0)) {
            $errors[] = 'Resolved Laravel environment basename must not be .env.';
        }

        if ($errors !== []) {
            return self::invalidEnvironmentRedirect($errors);
        }

        return [
            'ok' => true,
            'errors' => [],
            // Always the base basename — Laravel may still select "{base}.{APP_ENV}" at bootstrap.
            'environment_path' => $resolvedWorkdir,
            'environment_file' => $expectedBasename,
            'base_environment_file_path' => $resolvedEnv,
            'resolved_load_path' => $loadPath,
            'selected_via_app_env_suffix' => $selectedViaSuffix,
        ];
    }

    /**
     * Redirect Laravel Dotenv loading to the disposable workdir file before Kernel::bootstrap().
     *
     * @param  array<string, string|null>|null  $snapshot  Optional override for tests; defaults to process env + booted state.
     */
    public static function redirectEnvironmentAwayFromProtectedDotEnv(Application $app, ?array $snapshot = null): void
    {
        $snapshot ??= [
            'token' => self::$runToken ?? self::env(self::ENV_TOKEN),
            'repository_root' => self::$repositoryRoot ?? self::env(self::ENV_REPO_ROOT),
            'workdir' => self::$workDir ?? self::env(self::ENV_WORKDIR),
            'environment_file' => self::$approvedEnvironmentFilePath ?? self::env(self::ENV_ENV_FILE),
            'app_env' => self::env('APP_ENV') ?? 'testing',
            'protected_dotenv' => (self::$repositoryRoot ?? self::env(self::ENV_REPO_ROOT) ?? '').DIRECTORY_SEPARATOR.'.env',
        ];

        $result = self::validateEnvironmentRedirect($snapshot);
        if ($result['ok'] !== true
            || $result['environment_path'] === null
            || $result['environment_file'] === null
            || $result['base_environment_file_path'] === null
            || $result['resolved_load_path'] === null) {
            throw new RuntimeException(
                "Phase 4C.3 refused to redirect Laravel environment loading.\n".implode("\n", $result['errors']),
            );
        }

        $app->useEnvironmentPath($result['environment_path']);
        $app->loadEnvironmentFrom($result['environment_file']);

        $resolvedAfter = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $app->environmentFilePath());
        $expectedJoin = str_replace(
            ['/', '\\'],
            DIRECTORY_SEPARATOR,
            $result['environment_path'].DIRECTORY_SEPARATOR.$result['environment_file'],
        );
        if ($resolvedAfter !== $expectedJoin) {
            throw new RuntimeException(
                'Phase 4C.3 environment redirect did not stick on the Application instance.',
            );
        }

        if (basename($resolvedAfter) === '.env' || strcasecmp(basename($resolvedAfter), '.env') === 0) {
            throw new RuntimeException(
                'Phase 4C.3 environment redirect resolved to a protected .env basename.',
            );
        }

        $afterReal = realpath($resolvedAfter);
        if ($afterReal === false
            || str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $afterReal) !== $result['base_environment_file_path']) {
            throw new RuntimeException(
                'Phase 4C.3 environment redirect path does not match the validated disposable base file.',
            );
        }

        $repoRoot = is_string($snapshot['repository_root'] ?? null) ? $snapshot['repository_root'] : null;
        $protected = is_string($snapshot['protected_dotenv'] ?? null) && $snapshot['protected_dotenv'] !== ''
            ? str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $snapshot['protected_dotenv'])
            : ($repoRoot !== null ? str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $repoRoot.DIRECTORY_SEPARATOR.'.env') : null);

        if ($protected !== null) {
            $protectedReal = realpath($protected);
            $protectedNorm = $protectedReal !== false
                ? str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $protectedReal)
                : $protected;
            $afterNorm = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $afterReal);
            if ($afterNorm === $protectedNorm || $result['resolved_load_path'] === $protectedNorm) {
                throw new RuntimeException(
                    'Phase 4C.3 environment redirect resolved to the protected repository .env.',
                );
            }
        }
    }

    public static function bootFromEnvironment(string $bootstrapDerivedRepoRoot): void
    {
        $env = [
            self::ENV_SQLITE_PATH => self::env(self::ENV_SQLITE_PATH),
            self::ENV_TOKEN => self::env(self::ENV_TOKEN),
            self::ENV_REPO_ROOT => self::env(self::ENV_REPO_ROOT),
            self::ENV_FORBIDDEN_DB => self::env(self::ENV_FORBIDDEN_DB),
            self::ENV_WORKDIR => self::env(self::ENV_WORKDIR),
            self::ENV_ENV_FILE => self::env(self::ENV_ENV_FILE),
            'DB_DATABASE' => self::env('DB_DATABASE'),
            'APP_ENV' => self::env('APP_ENV') ?? 'testing',
        ];

        $result = self::validate($env, $bootstrapDerivedRepoRoot);
        if ($result['ok'] !== true) {
            throw new RuntimeException(
                "Phase 4C.3 SQLite concurrency isolation refused to boot.\n".implode("\n", $result['errors']),
            );
        }

        $normalizedPath = $result['approved_database_path'];
        $normalizedRepo = $result['repository_root'];
        $normalizedForbidden = $result['forbidden_database_path'];
        $token = $result['token'];
        $workdir = $result['workdir'];
        $envFilePath = $result['approved_environment_file_path'];

        if ($normalizedPath === null || $normalizedRepo === null || $normalizedForbidden === null || $token === null || $workdir === null || $envFilePath === null) {
            throw new RuntimeException('Phase 4C.3 isolation validation returned an incomplete success snapshot.');
        }

        // Store the base disposable env file (not the APP_ENV suffix) for child redirect claims.
        $baseEnvFile = $workdir.DIRECTORY_SEPARATOR.self::environmentBasenameForToken($token);
        $baseEnvReal = realpath($baseEnvFile);
        if ($baseEnvReal === false) {
            throw new RuntimeException('Phase 4C.3 base disposable environment file disappeared after validation.');
        }
        $baseEnvReal = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $baseEnvReal);

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
            self::ENV_ENV_FILE => $baseEnvReal,
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
        self::$approvedEnvironmentFilePath = $baseEnvReal;
    }

    public static function approvedDatabasePath(): string
    {
        return self::$approvedDatabasePath ?? throw new RuntimeException('Phase 4C.3 isolation has not been booted.');
    }

    public static function repositoryRoot(): string
    {
        return self::$repositoryRoot ?? throw new RuntimeException('Phase 4C.3 isolation has not been booted.');
    }

    public static function forbiddenDatabasePath(): string
    {
        return self::$forbiddenDatabasePath ?? throw new RuntimeException('Phase 4C.3 isolation has not been booted.');
    }

    public static function runToken(): string
    {
        return self::$runToken ?? throw new RuntimeException('Phase 4C.3 isolation has not been booted.');
    }

    public static function workDir(): string
    {
        return self::$workDir ?? throw new RuntimeException('Phase 4C.3 isolation has not been booted.');
    }

    public static function approvedEnvironmentFilePath(): string
    {
        return self::$approvedEnvironmentFilePath ?? throw new RuntimeException('Phase 4C.3 isolation has not been booted.');
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
     *     workdir: null,
     *     approved_environment_file_path: null
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
            'approved_environment_file_path' => null,
        ];
    }

    /**
     * @param  list<string>  $errors
     * @return array{
     *     ok: bool,
     *     errors: list<string>,
     *     environment_path: null,
     *     environment_file: null,
     *     base_environment_file_path: null,
     *     resolved_load_path: null,
     *     selected_via_app_env_suffix: bool
     * }
     */
    private static function invalidEnvironmentRedirect(array $errors): array
    {
        return [
            'ok' => false,
            'errors' => $errors,
            'environment_path' => null,
            'environment_file' => null,
            'base_environment_file_path' => null,
            'resolved_load_path' => null,
            'selected_via_app_env_suffix' => false,
        ];
    }
}
