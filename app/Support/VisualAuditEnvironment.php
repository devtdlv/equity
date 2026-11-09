<?php

namespace App\Support;

use InvalidArgumentException;
use RuntimeException;

/**
 * Isolation checks for the disposable visual-audit environment.
 *
 * Never reads the real application .env file. Callers must boot Laravel with
 * `--env=visual-audit` so `.env.visual-audit` is the loaded Dotenv file.
 */
final class VisualAuditEnvironment
{
    public const APP_ENV = 'visual-audit';

    public const DEMO_EMAIL = 'demo@equity.local';

    public const DEMO_NAME = 'Equity Demo User';

    /** Fictional password for local screenshot login only. Not a production secret. */
    public const DEMO_PASSWORD = 'VisualAudit!Demo99';

    public const ENV_FILE_NAME = '.env.visual-audit';

    public const MARKER_ENV_KEY = 'EQUITY_VISUAL_AUDIT';

    private static ?string $dataDirectoryOverride = null;

    private static ?string $envFilePathOverride = null;

    /**
     * Absolute directory outside the repository for disposable SQLite + artifacts.
     */
    public static function defaultDataDirectory(): string
    {
        if (self::$dataDirectoryOverride !== null) {
            return self::$dataDirectoryOverride;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $local = getenv('LOCALAPPDATA');
            if (! is_string($local) || $local === '') {
                $profile = getenv('USERPROFILE');
                $local = is_string($profile) && $profile !== ''
                    ? $profile.'\\AppData\\Local'
                    : sys_get_temp_dir();
            }

            return $local.DIRECTORY_SEPARATOR.'EquityVisualAudit';
        }

        $home = getenv('HOME');
        if (! is_string($home) || $home === '') {
            $home = sys_get_temp_dir();
        }

        return $home.DIRECTORY_SEPARATOR.'.equity-visual-audit';
    }

    /**
     * Test-only override of the dedicated disposable data directory.
     * Refuses to run outside automated tests.
     */
    public static function useDataDirectoryOverrideForTesting(string $absolutePath): void
    {
        if (! self::testingContextAllowed()) {
            throw new RuntimeException('Data directory override is only available during automated tests.');
        }

        $normalized = self::normalizePathSeparators($absolutePath);
        if (! self::isAbsolutePath($normalized)) {
            throw new InvalidArgumentException('Test data directory override must be an absolute path.');
        }

        self::$dataDirectoryOverride = self::canonicalizeLogicalPath($normalized);
    }

    public static function clearDataDirectoryOverride(): void
    {
        if (! self::testingContextAllowed()) {
            throw new RuntimeException('Data directory override is only available during automated tests.');
        }

        self::$dataDirectoryOverride = null;
    }

    /**
     * Test-only override of the `.env.visual-audit` path.
     * Refuses to run outside automated tests.
     */
    public static function useEnvFilePathOverrideForTesting(string $absolutePath): void
    {
        if (! self::testingContextAllowed()) {
            throw new RuntimeException('Env file path override is only available during automated tests.');
        }

        $normalized = self::normalizePathSeparators($absolutePath);
        if (! self::isAbsolutePath($normalized)) {
            throw new InvalidArgumentException('Test env file path override must be an absolute path.');
        }

        self::$envFilePathOverride = $normalized;
    }

    public static function clearEnvFilePathOverride(): void
    {
        if (! self::testingContextAllowed()) {
            throw new RuntimeException('Env file path override is only available during automated tests.');
        }

        self::$envFilePathOverride = null;
    }

    public static function defaultDatabasePath(): string
    {
        return self::defaultDataDirectory().DIRECTORY_SEPARATOR.'database.sqlite';
    }

    public static function forbiddenDatabasePath(): string
    {
        return self::repositoryRoot().'/database/database.sqlite';
    }

    public static function repositoryRoot(): string
    {
        if (function_exists('base_path')) {
            try {
                return str_replace('\\', '/', base_path());
            } catch (\Throwable) {
                // Fall through when the container is unavailable (standalone scripts).
            }
        }

        // app/Support -> repository root
        return str_replace('\\', '/', dirname(__DIR__, 2));
    }

    public static function envFilePath(): string
    {
        if (self::$envFilePathOverride !== null) {
            return self::$envFilePathOverride;
        }

        return self::repositoryRoot().'/'.self::ENV_FILE_NAME;
    }

    /**
     * Validate a disposable SQLite path without creating files.
     *
     * Returns a canonical absolute path using forward slashes.
     *
     * @throws InvalidArgumentException when the path is unsafe or ambiguous
     */
    public static function assertDisposableDatabasePath(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '' || $trimmed === ':memory:') {
            throw new InvalidArgumentException('Disposable SQLite path must be a non-empty filesystem path.');
        }

        $normalizedInput = self::normalizePathSeparators($trimmed);
        if (! self::isAbsolutePath($normalizedInput)) {
            throw new InvalidArgumentException('Disposable SQLite path must be absolute.');
        }

        if (str_contains($normalizedInput, "\0")) {
            throw new InvalidArgumentException('Disposable SQLite path contains a NUL byte.');
        }

        $allowedRoot = self::canonicalAllowedDataDirectory();
        $logicalPath = self::canonicalizeLogicalPath($normalizedInput);
        $repoRoot = self::canonicalizeLogicalPath(self::repositoryRoot());

        if (self::pathsEqual($logicalPath, self::forbiddenDatabasePath())) {
            throw new InvalidArgumentException('Refusing to use the real database/database.sqlite path.');
        }

        if (self::pathIsUnderOrEqual($logicalPath, $repoRoot)) {
            throw new InvalidArgumentException('Disposable SQLite path must be outside the Equity repository.');
        }

        if (! self::pathIsUnderOrEqual($logicalPath, $allowedRoot)) {
            throw new InvalidArgumentException(
                'Disposable SQLite path must be under the dedicated EquityVisualAudit data directory.',
            );
        }

        if (is_link($trimmed) || is_file($trimmed) || is_dir($trimmed)) {
            $resolved = realpath($trimmed);
            if ($resolved === false) {
                throw new InvalidArgumentException('Disposable SQLite path could not be resolved safely.');
            }

            $resolvedNormalized = self::normalizePathSeparators($resolved);
            if (! self::pathIsUnderOrEqual($resolvedNormalized, $allowedRoot)) {
                throw new InvalidArgumentException(
                    'Disposable SQLite path resolves outside the dedicated EquityVisualAudit data directory.',
                );
            }

            if (self::pathIsUnderOrEqual($resolvedNormalized, $repoRoot)) {
                throw new InvalidArgumentException('Disposable SQLite path resolves inside the Equity repository.');
            }

            if (self::pathsEqual($resolvedNormalized, self::forbiddenDatabasePath())) {
                throw new InvalidArgumentException('Refusing to use the real database/database.sqlite path.');
            }

            return $resolvedNormalized;
        }

        // Non-existent target: resolve the deepest existing ancestor. When the dedicated
        // data directory does not exist yet, the ancestor must still be a real parent of
        // that directory. Otherwise the ancestor itself must remain under the allowed root.
        $resolvedAncestor = self::resolveExistingAncestor($logicalPath);
        if ($resolvedAncestor === null) {
            throw new InvalidArgumentException(
                'Disposable SQLite path is ambiguous because no existing ancestor could be resolved.',
            );
        }

        if (is_dir($allowedRoot) || is_link($allowedRoot)) {
            if (! self::pathIsUnderOrEqual($resolvedAncestor, $allowedRoot)
                && ! self::pathsEqual($resolvedAncestor, $allowedRoot)) {
                throw new InvalidArgumentException(
                    'Disposable SQLite path ancestor resolves outside the dedicated EquityVisualAudit data directory.',
                );
            }
        } elseif (! self::pathIsUnderOrEqual($allowedRoot, $resolvedAncestor)
            && ! self::pathsEqual($allowedRoot, $resolvedAncestor)) {
            throw new InvalidArgumentException(
                'Disposable SQLite path ancestor does not contain the dedicated EquityVisualAudit data directory.',
            );
        }

        return $logicalPath;
    }

    /**
     * Prepare `.env.visual-audit` and an empty disposable SQLite file when missing.
     *
     * Preserves an existing env file and never overwrites an existing SQLite file.
     * Does not support unsafe regeneration of keys or silent database retargeting.
     *
     * @return array{
     *     env_path: string,
     *     database_path: string,
     *     created_database: bool,
     *     wrote_env: bool
     * }
     */
    public static function prepareDisposableEnvironment(?string $databasePath = null): array
    {
        $envPath = self::envFilePath();
        if (is_file($envPath)) {
            throw new RuntimeException(
                self::ENV_FILE_NAME.' already exists and will be preserved. '
                .'Remove it manually only if you intentionally want to recreate the visual-audit env.',
            );
        }

        $requested = is_string($databasePath) && trim($databasePath) !== ''
            ? trim($databasePath)
            : self::defaultDatabasePath();

        $validated = self::assertDisposableDatabasePath($requested);
        $allowedRoot = self::canonicalAllowedDataDirectory();

        if (! is_dir($allowedRoot) && ! mkdir($allowedRoot, 0700, true) && ! is_dir($allowedRoot)) {
            throw new RuntimeException('Unable to create disposable data directory: '.$allowedRoot);
        }

        // Re-validate after ensuring the allowed root exists so ancestor resolution is definitive.
        $validated = self::assertDisposableDatabasePath($requested);

        $directory = dirname(str_replace('/', DIRECTORY_SEPARATOR, $validated));
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create disposable database directory: '.$directory);
        }

        $createdDatabase = false;
        if (is_file($validated) || is_link($validated)) {
            // Preserve existing disposable DB bytes (and reject unsafe links via assert above).
            self::assertDisposableDatabasePath($validated);
        } else {
            if (file_put_contents($validated, '') === false) {
                throw new RuntimeException('Unable to create empty disposable SQLite file.');
            }
            $createdDatabase = true;
        }

        $finalDatabasePath = self::assertDisposableDatabasePath($validated);

        $contents = self::renderEnvFileContents([
            'DB_DATABASE' => $finalDatabasePath,
            'APP_KEY' => self::generateApplicationKey(),
            'APP_URL' => 'http://127.0.0.1:8000',
        ]);

        if (file_put_contents($envPath, $contents) === false) {
            throw new RuntimeException('Unable to write '.self::ENV_FILE_NAME);
        }

        return [
            'env_path' => $envPath,
            'database_path' => $finalDatabasePath,
            'created_database' => $createdDatabase,
            'wrote_env' => true,
        ];
    }

    /**
     * @return list<array{label: string, passed: bool, detail: string|null}>
     */
    public static function verificationChecks(): array
    {
        $checks = [];

        $checks[] = self::check(
            'APP_ENV is visual-audit',
            (string) config('app.env') === self::APP_ENV,
            (string) config('app.env') !== self::APP_ENV
                ? 'APP_ENV must be "visual-audit" (got "'.config('app.env').'"). Load with --env=visual-audit.'
                : null,
        );

        $visualAuditEnabled = filter_var(config('equity.visual_audit.enabled'), FILTER_VALIDATE_BOOL);
        $checks[] = self::check(
            'EQUITY_VISUAL_AUDIT enabled',
            $visualAuditEnabled,
            $visualAuditEnabled ? null : 'EQUITY_VISUAL_AUDIT must be true in the visual-audit environment file.',
        );

        $sqliteSelected = (string) config('database.default') === 'sqlite';
        $checks[] = self::check(
            'SQLite connection selected',
            $sqliteSelected,
            $sqliteSelected ? null : 'DB_CONNECTION must be sqlite for visual-audit.',
        );

        $databaseConfigured = (string) config('database.connections.sqlite.database');
        $pathCheck = self::evaluateConfiguredDatabasePath($databaseConfigured);
        $checks[] = self::check(
            'Disposable DB path under EquityVisualAudit / not real DB',
            $pathCheck['passed'],
            $pathCheck['detail'],
        );

        if ($pathCheck['passed']) {
            $filePresent = self::configuredDatabaseFilePresent($databaseConfigured);
            $checks[] = self::check(
                'Disposable DB file exists (empty or present)',
                $filePresent['passed'],
                $filePresent['detail'],
            );
        } else {
            $checks[] = self::check(
                'Disposable DB file exists (empty or present)',
                false,
                'Skipped until the disposable database path is valid.',
            );
        }

        $appKey = (string) config('app.key');
        $keyOk = $appKey !== '' && $appKey !== 'base64:';
        $checks[] = self::check(
            'Dedicated APP_KEY present',
            $keyOk,
            $keyOk ? null : 'APP_KEY must be set to a dedicated visual-audit key.',
        );

        $sessionOk = (string) config('session.driver') === 'database';
        $checks[] = self::check(
            'SESSION_DRIVER=database',
            $sessionOk,
            $sessionOk ? null : 'SESSION_DRIVER must be database so sessions stay on the disposable SQLite file.',
        );

        $cacheOk = (string) config('cache.default') === 'database';
        $checks[] = self::check(
            'CACHE_STORE=database',
            $cacheOk,
            $cacheOk ? null : 'CACHE_STORE must be database so cache stays on the disposable SQLite file.',
        );

        $mailer = (string) config('mail.default');
        $mailOk = in_array($mailer, ['array', 'log'], true);
        $checks[] = self::check(
            'MAIL non-delivering',
            $mailOk,
            $mailOk ? null : 'MAIL_MAILER must be array or log (non-delivering).',
        );

        $queueOk = (string) config('queue.default') === 'sync';
        $checks[] = self::check(
            'QUEUE_CONNECTION=sync',
            $queueOk,
            $queueOk ? null : 'QUEUE_CONNECTION must be sync (no background workers).',
        );

        $immediateDisabled = ! filter_var(config('equity.sqlite.financial_immediate'), FILTER_VALIDATE_BOOL);
        $checks[] = self::check(
            'SQLite IMMEDIATE disabled',
            $immediateDisabled,
            $immediateDisabled ? null : 'equity.sqlite.financial_immediate must remain false.',
        );

        $configCache = base_path('bootstrap/cache/config.php');
        $configCacheAbsent = ! is_file($configCache);
        $checks[] = self::check(
            'Config cache absent',
            $configCacheAbsent,
            $configCacheAbsent ? null : 'bootstrap/cache/config.php exists; refuse visual-audit boot until config cache is cleared.',
        );

        $envLoaded = (string) config('app.env') === self::APP_ENV && $visualAuditEnabled;
        $checks[] = self::check(
            'CLI loaded intended env file',
            $envLoaded,
            $envLoaded ? null : 'CLI does not appear to have loaded the visual-audit environment file.',
        );

        return $checks;
    }

    /**
     * @return list<string>
     */
    public static function verify(): array
    {
        $failures = [];
        foreach (self::verificationChecks() as $check) {
            if (! $check['passed'] && is_string($check['detail']) && $check['detail'] !== '') {
                $failures[] = $check['detail'];
            } elseif (! $check['passed']) {
                $failures[] = $check['label'].' failed.';
            }
        }

        return $failures;
    }

    public static function assertSafeToSeed(): void
    {
        $failures = self::verify();
        if ($failures !== []) {
            throw new RuntimeException(
                "Visual-audit environment isolation failed:\n - ".implode("\n - ", $failures),
            );
        }
    }

    public static function assertSafeDatabaseTarget(): void
    {
        self::assertSafeToSeed();

        $database = self::normalizedDatabasePath((string) config('database.connections.sqlite.database'));
        if ($database === ':memory:') {
            return;
        }

        if (! is_file($database)) {
            throw new RuntimeException('Disposable SQLite file does not exist yet: '.$database);
        }
    }

    public static function allowsMemoryDatabaseForTests(): bool
    {
        return app()->environment('testing')
            && filter_var(config('equity.visual_audit.allow_memory_for_tests'), FILTER_VALIDATE_BOOL);
    }

    public static function generateApplicationKey(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }

    public static function normalizedDatabasePath(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === ':memory:') {
            return ':memory:';
        }

        if ($trimmed === '') {
            return '';
        }

        try {
            return self::assertDisposableDatabasePath($trimmed);
        } catch (InvalidArgumentException) {
            // Keep a stable display/compare form for verify messaging without claiming safety.
            $resolved = realpath($trimmed);
            if ($resolved !== false) {
                return self::normalizePathSeparators($resolved);
            }

            if (self::isAbsolutePath(self::normalizePathSeparators($trimmed))) {
                try {
                    return self::canonicalizeLogicalPath(self::normalizePathSeparators($trimmed));
                } catch (InvalidArgumentException) {
                    return self::normalizePathSeparators($trimmed);
                }
            }

            return self::normalizePathSeparators($trimmed);
        }
    }

    public static function pathsEqual(string $left, string $right): bool
    {
        return strcasecmp(
            rtrim(self::normalizePathSeparators($left), '/'),
            rtrim(self::normalizePathSeparators($right), '/'),
        ) === 0;
    }

    /**
     * @param  array<string, string|bool|null>  $overrides
     */
    public static function renderEnvFileContents(array $overrides = []): string
    {
        $database = (string) ($overrides['DB_DATABASE'] ?? self::defaultDatabasePath());
        $appKey = (string) ($overrides['APP_KEY'] ?? self::generateApplicationKey());
        $appUrl = (string) ($overrides['APP_URL'] ?? 'http://127.0.0.1:8000');

        if ($appKey === '') {
            throw new InvalidArgumentException('APP_KEY override must not be empty.');
        }

        $databaseNormalized = self::normalizePathSeparators($database);

        return <<<ENV
APP_NAME=Equity
APP_ENV=visual-audit
APP_KEY={$appKey}
APP_DEBUG=true
APP_URL={$appUrl}

EQUITY_VISUAL_AUDIT=true

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

APP_MAINTENANCE_DRIVER=file

BCRYPT_ROUNDS=12

LOG_CHANNEL=stderr
LOG_LEVEL=debug

DB_CONNECTION=sqlite
DB_DATABASE={$databaseNormalized}
DB_FOREIGN_KEYS=true
DB_BUSY_TIMEOUT=5000
DB_JOURNAL_MODE=wal

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync

CACHE_STORE=database
CACHE_PREFIX=equity_visual_audit_

MAIL_MAILER=array
MAIL_FROM_ADDRESS="demo@equity.local"
MAIL_FROM_NAME="\${APP_NAME}"

AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=
AWS_USE_PATH_STYLE_ENDPOINT=false

VITE_APP_NAME="\${APP_NAME}"

ENV;
    }

    /**
     * @return array{label: string, passed: bool, detail: string|null}
     */
    private static function check(string $label, bool $passed, ?string $detail): array
    {
        return [
            'label' => $label,
            'passed' => $passed,
            'detail' => $detail,
        ];
    }

    /**
     * @return array{passed: bool, detail: string|null}
     */
    private static function evaluateConfiguredDatabasePath(string $databaseConfigured): array
    {
        $trimmed = trim($databaseConfigured);
        if ($trimmed === ':memory:') {
            if (self::allowsMemoryDatabaseForTests()) {
                return ['passed' => true, 'detail' => null];
            }

            return [
                'passed' => false,
                'detail' => 'SQLite :memory: is not allowed for visual-audit seeding outside automated tests.',
            ];
        }

        if ($trimmed === '' || $trimmed === '0') {
            return [
                'passed' => false,
                'detail' => 'DB_DATABASE is empty; refuse to use the default repository SQLite path.',
            ];
        }

        try {
            self::assertDisposableDatabasePath($trimmed);

            return ['passed' => true, 'detail' => null];
        } catch (InvalidArgumentException $exception) {
            return [
                'passed' => false,
                'detail' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array{passed: bool, detail: string|null}
     */
    private static function configuredDatabaseFilePresent(string $databaseConfigured): array
    {
        $trimmed = trim($databaseConfigured);
        if ($trimmed === ':memory:') {
            if (self::allowsMemoryDatabaseForTests()) {
                return ['passed' => true, 'detail' => null];
            }

            return [
                'passed' => false,
                'detail' => 'SQLite :memory: is not allowed for visual-audit seeding outside automated tests.',
            ];
        }

        try {
            $validated = self::assertDisposableDatabasePath($trimmed);
        } catch (InvalidArgumentException $exception) {
            return [
                'passed' => false,
                'detail' => $exception->getMessage(),
            ];
        }

        if (! is_file($validated)) {
            return [
                'passed' => false,
                'detail' => 'Disposable SQLite file does not exist yet: '.$validated,
            ];
        }

        return ['passed' => true, 'detail' => null];
    }

    private static function canonicalAllowedDataDirectory(): string
    {
        $logical = self::canonicalizeLogicalPath(self::normalizePathSeparators(self::defaultDataDirectory()));
        if (is_dir($logical)) {
            $resolved = realpath($logical);
            if ($resolved === false) {
                throw new InvalidArgumentException('Dedicated EquityVisualAudit data directory could not be resolved.');
            }

            return self::normalizePathSeparators($resolved);
        }

        return $logical;
    }

    private static function normalizePathSeparators(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || (bool) preg_match('/^[A-Za-z]:\//', $path);
    }

    /**
     * Collapse . / .. without touching the filesystem. Rejects escape past the root.
     */
    private static function canonicalizeLogicalPath(string $path): string
    {
        $path = self::normalizePathSeparators($path);
        if (! self::isAbsolutePath($path)) {
            throw new InvalidArgumentException('Path must be absolute before canonicalization.');
        }

        $prefix = '';
        $remainder = $path;
        if (preg_match('/^([A-Za-z]:)(\/.*)?$/', $path, $matches) === 1) {
            $prefix = strtoupper($matches[1]);
            $remainder = $matches[2] ?? '/';
        } elseif (str_starts_with($path, '/')) {
            $prefix = '';
            $remainder = $path;
        }

        $segments = [];
        foreach (explode('/', trim($remainder, '/')) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    throw new InvalidArgumentException('Path escapes its absolute root via "..".');
                }
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        if ($prefix !== '') {
            return $prefix.'/'.implode('/', $segments);
        }

        return '/'.implode('/', $segments);
    }

    private static function pathIsUnderOrEqual(string $path, string $directory): bool
    {
        $normalizedPath = rtrim(self::normalizePathSeparators($path), '/');
        $normalizedDirectory = rtrim(self::normalizePathSeparators($directory), '/');

        if (self::pathsEqual($normalizedPath, $normalizedDirectory)) {
            return true;
        }

        $prefix = $normalizedDirectory.'/';

        return strncasecmp($normalizedPath, $prefix, strlen($prefix)) === 0;
    }

    private static function resolveExistingAncestor(string $logicalPath): ?string
    {
        $current = $logicalPath;
        while (true) {
            $parent = dirname($current);
            if ($parent === $current) {
                return null;
            }

            if (is_dir($parent) || is_link($parent) || is_file($parent)) {
                $resolved = realpath($parent);
                if ($resolved === false) {
                    return null;
                }

                return self::normalizePathSeparators($resolved);
            }

            $current = self::normalizePathSeparators($parent);
        }
    }

    private static function testingContextAllowed(): bool
    {
        if (defined('PHPUNIT_COMPOSER_INSTALL') || defined('PEST_RUNNING')) {
            return true;
        }

        foreach ([getenv('APP_ENV'), $_ENV['APP_ENV'] ?? null, $_SERVER['APP_ENV'] ?? null] as $env) {
            if (is_string($env) && $env === 'testing') {
                return true;
            }
        }

        if (function_exists('app')) {
            try {
                return app()->environment('testing');
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }
}
