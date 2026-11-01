<?php

/**
 * Offline, fail-closed integration configuration preflight (layer 1).
 *
 * Validates explicitly supplied CLI values only. Does not bootstrap Laravel,
 * read .env / config files, inspect process environment, or connect to a database.
 */

declare(strict_types=1);

const EQUITY_INTEGRATION_APPROVED_DATABASE = 'equity_it_6c2_20261101';
const EQUITY_INTEGRATION_APPROVED_DRIVER = 'mariadb';
const EQUITY_INTEGRATION_APPROVED_APP_ENV = 'integration';
const EQUITY_INTEGRATION_APPROVED_USERNAME = 'equity_it_6c2';
const EQUITY_INTEGRATION_APPROVED_HOST = '127.0.0.1';
const EQUITY_INTEGRATION_PROTECTED_DATABASE = 'equity';

/**
 * @return list<string>
 */
function equity_integration_protected_database_names(): array
{
    return [
        EQUITY_INTEGRATION_PROTECTED_DATABASE,
        'laravel',
        'mysql',
        'information_schema',
        'performance_schema',
        'sys',
        'test',
        'testing',
        ':memory:',
        'database.sqlite',
    ];
}

/**
 * @return list<string>
 */
function equity_integration_required_keys(): array
{
    return ['database', 'driver', 'app-env', 'host', 'port', 'username'];
}

/**
 * @return list<string>
 */
function equity_integration_forbidden_cli_flags(): array
{
    return [
        'password',
        'secret',
        'token',
        'url',
        'database-url',
        'db-url',
        'dsn',
        'socket',
        'unix-socket',
        'db-socket',
        'unix_socket',
        'db_socket',
        'database_url',
        'db-password',
        'db_password',
    ];
}

function equity_integration_is_blank(?string $value): bool
{
    return $value === null || trim($value) === '';
}

function equity_integration_is_root_like_username(string $username): bool
{
    $normalized = strtolower($username);

    return $normalized === 'root'
        || str_starts_with($normalized, 'root@')
        || str_starts_with($normalized, 'root/')
        || preg_match('/(^|[\\/\\\\])root(@|$)/', $normalized) === 1;
}

/**
 * @return list<string>
 */
function equity_integration_host_errors(string $host): array
{
    $errors = [];
    $lower = strtolower($host);

    if ($lower === 'localhost' || str_ends_with($lower, '.localhost')) {
        $errors[] = 'Host localhost is rejected. Use exactly '.EQUITY_INTEGRATION_APPROVED_HOST.'.';
    }

    if ($host === '0.0.0.0' || $host === '*' || $host === '::' || $host === '::1' || $lower === '[::1]') {
        $errors[] = 'Wildcard or unspecified bind addresses are rejected.';
    }

    if (str_contains($host, '://') || str_contains($host, '/') || str_contains($host, '\\') || str_contains($host, '@') || str_contains($host, ':')) {
        $errors[] = 'URL-like or path-like hosts are rejected.';
    }

    if ($host !== EQUITY_INTEGRATION_APPROVED_HOST) {
        $errors[] = 'Host must be exactly '.EQUITY_INTEGRATION_APPROVED_HOST.'.';
    }

    return $errors;
}

/**
 * @param list<string> $argv
 * @return array{ok: bool, values: array<string, string>, errors: list<string>}
 */
function equity_integration_parse_argv(array $argv): array
{
    $values = [];
    $errors = [];
    $args = array_slice($argv, 1);

    if ($args === []) {
        return [
            'ok' => false,
            'values' => [],
            'errors' => ['No configuration was supplied. Pass explicit --database, --driver, --app-env, --host, --port, and --username flags. Process environment, .env, and framework defaults are never used.'],
        ];
    }

    foreach ($args as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $errors[] = 'Help cannot be combined with configuration flags. Use --help by itself.';
            continue;
        }

        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            $errors[] = 'Arguments must use the form --key=value. Bare flags, positional values, and environment fallbacks are rejected.';
            continue;
        }

        $parts = explode('=', substr($arg, 2), 2);
        $key = $parts[0];
        $value = $parts[1] ?? '';

        if ($key === '') {
            $errors[] = 'An empty flag name was supplied.';
            continue;
        }

        if (in_array($key, equity_integration_forbidden_cli_flags(), true)) {
            $errors[] = 'Secret, socket, or connection-string flags are not accepted. Pass only nonsecret identifiers.';
            continue;
        }

        if (!in_array($key, equity_integration_required_keys(), true)) {
            $errors[] = 'Unknown flag "--'.$key.'" is rejected. Only --database, --driver, --app-env, --host, --port, and --username are allowed.';
            continue;
        }

        if (array_key_exists($key, $values)) {
            $errors[] = 'Flag --'.$key.' was supplied more than once.';
            continue;
        }

        $values[$key] = $value;
    }

    return [
        'ok' => $errors === [],
        'values' => $values,
        'errors' => $errors,
    ];
}

/**
 * @param array<string, string> $values
 * @return list<string>
 */
function equity_integration_validate(array $values): array
{
    $errors = [];

    foreach (equity_integration_required_keys() as $key) {
        if (!array_key_exists($key, $values)) {
            if ($key === 'database') {
                $errors[] = 'Approved disposable database identity was not explicitly supplied. Required exact value: '.EQUITY_INTEGRATION_APPROVED_DATABASE.'.';
            } elseif ($key === 'username') {
                $errors[] = 'Dedicated username was not explicitly supplied. Required exact value: '.EQUITY_INTEGRATION_APPROVED_USERNAME.'.';
            } else {
                $errors[] = 'Missing required --'.$key.' value. Defaults are not applied.';
            }
            continue;
        }

        if (equity_integration_is_blank($values[$key])) {
            $errors[] = 'Value for --'.$key.' is missing or whitespace-only.';
        }
    }

    if ($errors !== []) {
        return $errors;
    }

    $database = $values['database'];
    $driver = $values['driver'];
    $appEnv = $values['app-env'];
    $host = $values['host'];
    $port = $values['port'];
    $username = $values['username'];

    if ($database !== trim($database) || $driver !== trim($driver) || $appEnv !== trim($appEnv)
        || $host !== trim($host) || $port !== trim($port) || $username !== trim($username)) {
        $errors[] = 'Leading or trailing whitespace is rejected. Supply exact trimmed values.';
    }

    $protected = equity_integration_protected_database_names();
    foreach ($protected as $name) {
        if (strcasecmp($database, $name) === 0) {
            $errors[] = 'Database name is protected or ambiguous and must never be targeted.';
            break;
        }
    }

    if ($database !== EQUITY_INTEGRATION_APPROVED_DATABASE) {
        $errors[] = 'Database name is not the exact approved disposable identity '.EQUITY_INTEGRATION_APPROVED_DATABASE.'.';
    }

    if ($driver !== EQUITY_INTEGRATION_APPROVED_DRIVER) {
        $errors[] = 'Driver must be explicitly set to '.EQUITY_INTEGRATION_APPROVED_DRIVER.'. sqlite, mysql, and omitted/default drivers are rejected.';
    }

    if (strcasecmp($appEnv, 'production') === 0) {
        $errors[] = 'APP_ENV production is rejected.';
    } elseif ($appEnv !== EQUITY_INTEGRATION_APPROVED_APP_ENV) {
        $errors[] = 'APP_ENV must be explicitly set to '.EQUITY_INTEGRATION_APPROVED_APP_ENV.'. local, testing, and other values are rejected as ambiguous.';
    }

    $errors = array_merge($errors, equity_integration_host_errors($host));

    if (!preg_match('/^[1-9][0-9]*$/', $port)) {
        $errors[] = 'Port must be an explicit integer without a leading zero. The default 3306 is not implied.';
    } else {
        $portNumber = (int) $port;
        if ($portNumber < 1 || $portNumber > 65535) {
            $errors[] = 'Port is outside the valid TCP range.';
        }
    }

    if (equity_integration_is_root_like_username($username)) {
        $errors[] = 'Username is a root-like account identity and is rejected.';
    }

    if ($username !== EQUITY_INTEGRATION_APPROVED_USERNAME) {
        $errors[] = 'Username must be the dedicated account '.EQUITY_INTEGRATION_APPROVED_USERNAME.'.';
    }

    return $errors;
}

/**
 * @param array<string, string> $values
 * @param list<string> $errors
 * @return list<string>
 */
function equity_integration_format_report(array $values, array $errors): array
{
    $lines = [];
    $passed = $errors === [];
    $lines[] = 'Equity integration preflight: '.($passed ? 'PASS' : 'FAIL');
    $lines[] = 'Mode: offline configuration check (no database connection, no Laravel bootstrap).';
    $lines[] = 'Layer: 1 (CLI operator-intent preflight).';
    $lines[] = 'Approved disposable database: '.EQUITY_INTEGRATION_APPROVED_DATABASE;
    $lines[] = 'Protected database: '.EQUITY_INTEGRATION_PROTECTED_DATABASE;
    $lines[] = 'database='.($values['database'] ?? '(missing)');
    $lines[] = 'driver='.($values['driver'] ?? '(missing)');
    $lines[] = 'app-env='.($values['app-env'] ?? '(missing)');
    $lines[] = 'host='.($values['host'] ?? '(missing)');
    $lines[] = 'port='.($values['port'] ?? '(missing)');
    $lines[] = 'username='.($values['username'] ?? '(missing)');

    if ($passed) {
        $lines[] = 'Offline identifiers match the isolation contract.';
        $lines[] = 'Limitation: this check does not prove which database Laravel would use after env resolution or config caching.';
        $lines[] = 'Run layer 2 (env-file checker) next. A connected identity check is not implemented.';
    } else {
        foreach ($errors as $error) {
            $lines[] = 'FAIL: '.$error;
        }
    }

    return $lines;
}

function equity_integration_usage(): string
{
    return implode("\n", [
        'Usage:',
        '  php scripts/integration-preflight.php --database='.EQUITY_INTEGRATION_APPROVED_DATABASE.' --driver=mariadb --app-env=integration --host='.EQUITY_INTEGRATION_APPROVED_HOST.' --port=3306 --username='.EQUITY_INTEGRATION_APPROVED_USERNAME,
        '',
        'This command never reads .env, config/database.php, or process environment values.',
        'It never prints passwords, tokens, or connection URLs, and it never connects to a database.',
    ]);
}

/**
 * @param list<string> $argv
 * @return array{exit_code: int, output: string, values: array<string, string>, errors: list<string>}
 */
function equity_integration_preflight(array $argv): array
{
    $args = array_slice($argv, 1);
    if ($args === ['--help'] || $args === ['-h']) {
        return [
            'exit_code' => 0,
            'output' => equity_integration_usage()."\n",
            'values' => [],
            'errors' => [],
        ];
    }

    $parsed = equity_integration_parse_argv($argv);
    $values = $parsed['values'];
    $errors = $parsed['errors'];

    if ($errors === []) {
        $errors = equity_integration_validate($values);
    }

    $lines = equity_integration_format_report($values, $errors);

    return [
        'exit_code' => $errors === [] ? 0 : 1,
        'output' => implode("\n", $lines)."\n",
        'values' => $values,
        'errors' => $errors,
    ];
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME'])
    && basename((string) $_SERVER['SCRIPT_FILENAME']) === 'integration-preflight.php') {
    $result = equity_integration_preflight($argv);
    fwrite($result['exit_code'] === 0 ? STDOUT : STDERR, $result['output']);
    exit($result['exit_code']);
}
