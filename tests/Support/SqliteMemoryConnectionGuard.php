<?php

namespace Tests\Support;

/**
 * Fail-closed SQLite :memory: decision logic for default application tests.
 *
 * Pure function: accepts an explicit configuration snapshot. Does not bootstrap
 * Laravel, open a database, or read process environment values as fallbacks.
 */
final class SqliteMemoryConnectionGuard
{
    public const APPROVED_CONNECTION = 'sqlite';

    public const APPROVED_DRIVER = 'sqlite';

    public const APPROVED_DATABASE = ':memory:';

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
            'transacting_connections',
            'config_loaded_from_cache',
            'resolved_config_cache_exists',
            'default_config_cache_exists',
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

        $default = $snapshot['default'];
        if (! is_string($default) || $default !== self::APPROVED_CONNECTION) {
            $errors[] = 'Default database connection is not sqlite.';
        }

        $connections = $snapshot['connections'];
        if (! is_array($connections) || $connections === []) {
            $errors[] = 'Database connections configuration is missing or malformed.';

            return ['ok' => false, 'errors' => $errors];
        }

        if (! array_key_exists(self::APPROVED_CONNECTION, $connections) || ! is_array($connections[self::APPROVED_CONNECTION])) {
            $errors[] = 'SQLite connection configuration is missing or malformed.';

            return ['ok' => false, 'errors' => $errors];
        }

        $sqlite = $connections[self::APPROVED_CONNECTION];
        $errors = array_merge($errors, self::connectionErrors($sqlite, 'sqlite'));

        if (is_string($default) && array_key_exists($default, $connections)) {
            if ($default !== self::APPROVED_CONNECTION) {
                $errors[] = 'Default connection name is not sqlite.';
            } elseif ($connections[$default] !== $sqlite && is_array($connections[$default])) {
                $errors = array_merge($errors, self::connectionErrors($connections[$default], 'default'));
            }
        }

        $transacting = self::normalizeTransactingConnections($snapshot['transacting_connections'], $default);
        $errors = array_merge($errors, $transacting['errors']);

        if ($transacting['ok'] && $transacting['connections'] !== [self::APPROVED_CONNECTION]) {
            $errors[] = 'Test transaction connections are not exactly sqlite.';
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
    private static function connectionErrors(array $connection, string $label): array
    {
        $errors = [];

        $driver = $connection['driver'] ?? null;
        if (! is_string($driver) || $driver !== self::APPROVED_DRIVER) {
            $errors[] = 'The '.$label.' connection driver is not sqlite.';
        }

        $database = $connection['database'] ?? null;
        if (! is_string($database) || $database !== self::APPROVED_DATABASE) {
            $errors[] = 'The '.$label.' connection database is not exactly :memory:.';
            if (is_string($database) && strcasecmp($database, 'equity') === 0) {
                $errors[] = 'Protected database name equity is targeted.';
            }
        }

        if (self::hasRedirectingSelector($connection['url'] ?? null)) {
            $errors[] = 'A database URL is present on the '.$label.' connection.';
        }

        foreach (['read', 'write'] as $split) {
            if (array_key_exists($split, $connection)) {
                $errors[] = 'Read/write splitting is present on the '.$label.' connection.';
                break;
            }
        }

        if (array_key_exists('sticky', $connection) && $connection['sticky']) {
            $errors[] = 'Sticky read/write routing is present on the '.$label.' connection.';
        }

        if (self::hasRedirectingSelector($connection['unix_socket'] ?? null)
            || self::hasRedirectingSelector($connection['database_url'] ?? null)) {
            $errors[] = 'An alternate database selector is present on the '.$label.' connection.';
        }

        return $errors;
    }

    /**
     * Normalize Laravel RefreshDatabase/DatabaseTransactions method output.
     * Null entries mean the default connection. Non-array or non-string names fail closed.
     *
     * @return array{ok: bool, connections: list<string>, errors: list<string>}
     */
    public static function normalizeTransactingConnections(mixed $raw, mixed $default): array
    {
        if (! is_array($raw)) {
            return [
                'ok' => false,
                'connections' => [],
                'errors' => ['Transaction connections could not be determined safely.'],
            ];
        }

        $names = [];
        foreach ($raw as $name) {
            if ($name === null) {
                if (! is_string($default) || $default === '' || $default !== trim($default)) {
                    return [
                        'ok' => false,
                        'connections' => [],
                        'errors' => ['Transaction connections could not be determined safely.'],
                    ];
                }
                $names[] = $default;
                continue;
            }

            if (! is_string($name) || $name === '' || $name !== trim($name)) {
                return [
                    'ok' => false,
                    'connections' => [],
                    'errors' => ['Transaction connections could not be determined safely.'],
                ];
            }

            $names[] = $name;
        }

        return [
            'ok' => true,
            'connections' => $names,
            'errors' => [],
        ];
    }

    private static function hasRedirectingSelector(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return true;
    }

    /**
     * @param  array{ok: bool, errors: list<string>}  $result
     */
    public static function failureMessage(array $result): string
    {
        $lines = ['Equity SQLite :memory: test guard failed. No database connection was opened.'];
        foreach ($result['errors'] as $error) {
            $lines[] = $error;
        }

        return implode("\n", $lines);
    }
}
