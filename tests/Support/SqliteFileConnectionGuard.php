<?php

namespace Tests\Support;

use Tests\Integration\SqliteFile\SqliteFileIsolation;

/**
 * Fail-closed guard for Phase 2 file-based SQLite integration tests.
 *
 * Accepts an explicit snapshot only. Does not open PDO connections or read .env.
 */
final class SqliteFileConnectionGuard
{
    public const APPROVED_CONNECTION = 'sqlite';

    public const APPROVED_DRIVER = 'sqlite';

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{ok: bool, errors: list<string>}
     */
    public static function evaluate(array $snapshot): array
    {
        $errors = [];

        foreach ([
            'default',
            'connections',
            'approved_database_path',
            'repository_root',
            'forbidden_database_path',
            'config_loaded_from_cache',
            'resolved_config_cache_exists',
            'default_config_cache_exists',
            'transacting_connections',
            'run_token',
            'expected_database_basename',
        ] as $key) {
            if (! array_key_exists($key, $snapshot)) {
                return [
                    'ok' => false,
                    'errors' => ['Database configuration snapshot is missing or malformed.'],
                ];
            }
        }

        if ($snapshot['config_loaded_from_cache'] === true
            || $snapshot['resolved_config_cache_exists'] === true
            || $snapshot['default_config_cache_exists'] === true) {
            $errors[] = 'Laravel config cache is loaded or present. Cached configuration is rejected.';
        } elseif (! is_bool($snapshot['config_loaded_from_cache'])
            || ! is_bool($snapshot['resolved_config_cache_exists'])
            || ! is_bool($snapshot['default_config_cache_exists'])) {
            $errors[] = 'Config-cache snapshot values are malformed.';
        }

        $token = $snapshot['run_token'];
        if (! is_string($token) || ! SqliteFileIsolation::isValidToken($token)) {
            $errors[] = 'Phase 2 run token is missing or malformed.';
            $token = null;
        }

        $default = $snapshot['default'];
        if (! is_string($default) || $default !== self::APPROVED_CONNECTION) {
            $errors[] = 'Default database connection is not sqlite.';
        }

        $connections = $snapshot['connections'];
        if (! is_array($connections) || ! isset($connections[self::APPROVED_CONNECTION]) || ! is_array($connections[self::APPROVED_CONNECTION])) {
            return [
                'ok' => false,
                'errors' => array_merge($errors, ['SQLite connection configuration is missing or malformed.']),
            ];
        }

        $expectedBasename = $snapshot['expected_database_basename'];
        if (! is_string($expectedBasename) || $expectedBasename === '') {
            $errors[] = 'Expected disposable database basename is missing.';
            $expectedBasename = null;
        } elseif ($token !== null) {
            $phase2 = SqliteFileIsolation::databaseBasenameForToken($token);
            $phase3 = 'equity-phase3-'.$token.'.sqlite';
            $phase4c3 = 'equity-phase4c3-'.$token.'.sqlite';
            if ($expectedBasename !== $phase2 && $expectedBasename !== $phase3 && $expectedBasename !== $phase4c3) {
                $errors[] = 'Expected disposable database basename does not match the run token phase prefix.';
            }
        }

        $sqlite = $connections[self::APPROVED_CONNECTION];
        $errors = array_merge($errors, self::connectionErrors(
            $sqlite,
            $snapshot['approved_database_path'],
            $snapshot['repository_root'],
            $snapshot['forbidden_database_path'],
            $token,
            $expectedBasename,
        ));

        $transacting = $snapshot['transacting_connections'];
        if (! is_array($transacting) || $transacting !== [self::APPROVED_CONNECTION]) {
            $errors[] = 'Test transaction connections are not exactly sqlite.';
        }

        foreach ($connections as $name => $connection) {
            if (! is_string($name) || ! is_array($connection)) {
                $errors[] = 'A secondary database connection definition is malformed.';
                continue;
            }

            if ($name === self::APPROVED_CONNECTION) {
                continue;
            }

            if ($default === $name) {
                $errors[] = 'Default connection must remain sqlite.';
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return list<string>
     */
    private static function connectionErrors(
        array $connection,
        mixed $approvedPath,
        mixed $repositoryRoot,
        mixed $forbiddenPath,
        ?string $token,
        ?string $expectedBasename,
    ): array {
        $errors = [];

        $driver = $connection['driver'] ?? null;
        if (! is_string($driver) || $driver !== self::APPROVED_DRIVER) {
            $errors[] = 'The sqlite connection driver is not sqlite.';
        }

        $database = $connection['database'] ?? null;
        if (! is_string($database) || $database === '' || $database === ':memory:') {
            $errors[] = 'The sqlite connection must be a file path, not :memory: or empty.';

            return $errors;
        }

        if (! is_string($approvedPath) || $approvedPath === '') {
            $errors[] = 'Approved disposable database path is missing.';

            return $errors;
        }

        $resolvedDatabase = self::normalizePath($database);
        $resolvedApproved = self::normalizePath($approvedPath);

        if ($resolvedDatabase === null || $resolvedApproved === null || $resolvedDatabase !== $resolvedApproved) {
            $errors[] = 'Resolved sqlite database path does not match the approved disposable file.';
        }

        if ($expectedBasename !== null) {
            if ($resolvedApproved !== null && basename($resolvedApproved) !== $expectedBasename) {
                $errors[] = 'Approved disposable database basename does not match the expected basename.';
            }
            if ($resolvedDatabase !== null && basename($resolvedDatabase) !== $expectedBasename) {
                $errors[] = 'Resolved sqlite database basename does not match the expected basename.';
            }
        } elseif ($token !== null) {
            $fallback = SqliteFileIsolation::databaseBasenameForToken($token);
            if ($resolvedApproved !== null && basename($resolvedApproved) !== $fallback) {
                $errors[] = 'Approved disposable database basename does not match the run token.';
            }
            if ($resolvedDatabase !== null && basename($resolvedDatabase) !== $fallback) {
                $errors[] = 'Resolved sqlite database basename does not match the run token.';
            }
        }

        if (is_string($forbiddenPath) && $forbiddenPath !== '') {
            $resolvedForbidden = self::normalizePath($forbiddenPath);
            if ($resolvedForbidden !== null && $resolvedDatabase === $resolvedForbidden) {
                $errors[] = 'Connection targets the protected application database file.';
            }

            if (is_string($repositoryRoot) && $repositoryRoot !== '') {
                $resolvedRoot = self::normalizePath($repositoryRoot);
                if ($resolvedRoot !== null) {
                    $expectedForbidden = $resolvedRoot.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite';
                    $expectedForbiddenReal = self::normalizePath($expectedForbidden);
                    $normalizedForbidden = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $forbiddenPath);
                    if ($expectedForbiddenReal !== null) {
                        if ($resolvedForbidden !== $expectedForbiddenReal) {
                            $errors[] = 'Forbidden database path does not match project database/database.sqlite.';
                        }
                    } elseif ($normalizedForbidden !== str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $expectedForbidden)) {
                        $errors[] = 'Forbidden database path does not match project database/database.sqlite.';
                    }
                }
            }
        }

        if (is_string($repositoryRoot) && $repositoryRoot !== '') {
            $resolvedRoot = self::normalizePath($repositoryRoot);
            if ($resolvedRoot !== null && $resolvedDatabase !== null && str_starts_with($resolvedDatabase, $resolvedRoot.DIRECTORY_SEPARATOR)) {
                $errors[] = 'Disposable database path must not be inside the repository.';
            }
        }

        if (self::hasTruthy($connection['url'] ?? null)
            || self::hasTruthy($connection['database_url'] ?? null)
            || self::hasTruthy($connection['unix_socket'] ?? null)) {
            $errors[] = 'A redirecting database selector is present on the sqlite connection.';
        }

        foreach (['read', 'write'] as $split) {
            if (array_key_exists($split, $connection)) {
                $errors[] = 'Read/write splitting is present on the sqlite connection.';
                break;
            }
        }

        if (array_key_exists('sticky', $connection) && $connection['sticky']) {
            $errors[] = 'Sticky read/write routing is present on the sqlite connection.';
        }

        return $errors;
    }

    private static function hasTruthy(mixed $value): bool
    {
        return ! ($value === null || $value === '');
    }

    private static function normalizePath(string $path): ?string
    {
        $real = realpath($path);
        if ($real === false) {
            return null;
        }

        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $real);
    }

    /**
     * @param  array{ok: bool, errors: list<string>}  $result
     */
    public static function failureMessage(array $result): string
    {
        $lines = ['Equity Phase 2 SQLite file guard failed. No protected database connection was opened by the guard.'];
        foreach ($result['errors'] as $error) {
            $lines[] = $error;
        }

        return implode("\n", $lines);
    }
}
