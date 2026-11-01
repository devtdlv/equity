<?php

/**
 * Offline env-file and Laravel config-cache checker (layer 2).
 *
 * Parses an explicitly supplied env file as data. Does not bootstrap Laravel,
 * open the protected .env, or connect to a database. Inherited process keys are
 * inspected for presence only and never used as configuration fallbacks.
 *
 * Supported format (narrow, fail-closed):
 *   - UTF-8 text without a BOM
 *   - Optional blank lines
 *   - Optional full-line comments starting with #
 *   - KEY=VALUE lines only (uppercase unquoted keys, unquoted values)
 *   - Required keys: APP_ENV, DB_CONNECTION, DB_DATABASE, DB_HOST, DB_PORT, DB_USERNAME
 *   - Optional withheld key: DB_PASSWORD (never printed)
 *   - Any other key, interpolation, quotes, export, or command substitution is FAIL
 */

declare(strict_types=1);

require_once __DIR__.DIRECTORY_SEPARATOR.'integration-preflight.php';

const EQUITY_RESOLVED_CONFIG_CACHE_RELATIVE = 'bootstrap/cache/config.php';
const EQUITY_RESOLVED_MAX_ENV_BYTES = 65536;

/**
 * @return list<string>
 */
function equity_resolved_required_env_keys(): array
{
    return [
        'APP_ENV',
        'DB_CONNECTION',
        'DB_DATABASE',
        'DB_HOST',
        'DB_PORT',
        'DB_USERNAME',
    ];
}

/**
 * @return list<string>
 */
function equity_resolved_optional_secret_keys(): array
{
    return ['DB_PASSWORD'];
}

/**
 * @return list<string>
 */
function equity_resolved_forbidden_env_keys(): array
{
    return [
        'APP_CONFIG_CACHE',
        'DB_URL',
        'DB_SOCKET',
        'DB_UNIX_SOCKET',
        'DATABASE_URL',
        'MYSQL_PWD',
        'DB_DSN',
        'REDIS_URL',
    ];
}

function equity_resolved_is_allowed_env_key(string $key): bool
{
    return in_array($key, equity_resolved_required_env_keys(), true)
        || in_array($key, equity_resolved_optional_secret_keys(), true);
}

function equity_resolved_is_forbidden_env_key(string $key): bool
{
    if (in_array($key, equity_resolved_forbidden_env_keys(), true)) {
        return true;
    }

    if (str_ends_with($key, '_URL') || str_ends_with($key, '_DSN') || str_ends_with($key, '_SOCKET')) {
        return true;
    }

    if (str_starts_with($key, 'DB_') && ! equity_resolved_is_allowed_env_key($key)) {
        return true;
    }

    return false;
}

function equity_resolved_default_project_root(): string
{
    return dirname(__DIR__);
}

/**
 * Canonical repository root derived from this script's location, not from caller flags.
 *
 * @return string|false
 */
function equity_resolved_canonical_repository_root(): string|false
{
    return realpath(dirname(__DIR__));
}

function equity_resolved_config_cache_path(string $root): string
{
    return $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, EQUITY_RESOLVED_CONFIG_CACHE_RELATIVE);
}

/**
 * @return list<string>
 */
function equity_resolved_dangerous_process_keys(): array
{
    return [
        'APP_ENV',
        'APP_CONFIG_CACHE',
        'DB_CONNECTION',
        'DB_DATABASE',
        'DB_HOST',
        'DB_PORT',
        'DB_USERNAME',
        'DB_PASSWORD',
        'DB_URL',
        'DB_SOCKET',
        'DB_UNIX_SOCKET',
        'DATABASE_URL',
        'MYSQL_PWD',
        'DB_DSN',
    ];
}

function equity_resolved_process_key_present(string $key): bool
{
    if (getenv($key) !== false) {
        return true;
    }

    if (array_key_exists($key, $_ENV)) {
        return true;
    }

    return array_key_exists($key, $_SERVER) && ! is_array($_SERVER[$key]);
}

/**
 * @return list<string>
 */
function equity_resolved_present_dangerous_process_keys(): array
{
    $present = [];
    foreach (equity_resolved_dangerous_process_keys() as $key) {
        if (equity_resolved_process_key_present($key)) {
            $present[] = $key;
        }
    }

    return $present;
}

/**
 * Presence-only. Accepts an explicit list of present keys; never reads values.
 *
 * @param list<string> $present
 * @return list<string>
 */
function equity_resolved_inherited_environment_errors_from_present_keys(array $present): array
{
    if ($present === []) {
        return [];
    }

    return [
        'Inherited process environment contains database or config selectors ('.implode(', ', $present).'). Values were not read as configuration and are not printed. Unset them before layer 2. This check does not prove Laravel\'s resolved connection.',
    ];
}

/**
 * Presence-only. Never returns or logs values.
 *
 * @return list<string>
 */
function equity_resolved_inherited_environment_errors(): array
{
    return equity_resolved_inherited_environment_errors_from_present_keys(
        equity_resolved_present_dangerous_process_keys(),
    );
}

function equity_resolved_normalize_path(string $path): string
{
    $normalized = str_replace('\\', '/', $path);
    $normalized = preg_replace('#/+#', '/', $normalized) ?? $normalized;

    return rtrim($normalized, '/');
}

function equity_resolved_paths_equal(string $left, string $right): bool
{
    return strcasecmp(equity_resolved_normalize_path($left), equity_resolved_normalize_path($right)) === 0;
}

function equity_resolved_is_protected_env_path(string $envFile, string $projectRoot): bool
{
    if (strcasecmp(basename($envFile), '.env') === 0) {
        return true;
    }

    $protected = equity_resolved_normalize_path($projectRoot).'/.env';

    return equity_resolved_paths_equal($envFile, $protected);
}

/**
 * @param list<string> $argv
 * @return array{ok: bool, env_file: ?string, project_root: string, errors: list<string>}
 */
function equity_resolved_parse_argv(array $argv): array
{
    $envFile = null;
    $projectRoot = equity_resolved_default_project_root();
    $errors = [];
    $args = array_slice($argv, 1);
    $seen = [];

    if ($args === []) {
        return [
            'ok' => false,
            'env_file' => null,
            'project_root' => $projectRoot,
            'errors' => ['No configuration was supplied. Pass explicit --env-file. Process environment, .env, and Laravel defaults are never used.'],
        ];
    }

    foreach ($args as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $errors[] = 'Help cannot be combined with configuration flags. Use --help by itself.';
            continue;
        }

        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            $errors[] = 'Arguments must use the form --key=value.';
            continue;
        }

        $parts = explode('=', substr($arg, 2), 2);
        $key = $parts[0];
        $value = $parts[1] ?? '';

        if ($key === '' || in_array($key, $seen, true)) {
            $errors[] = $key === ''
                ? 'An empty flag name was supplied.'
                : 'Flag --'.$key.' was supplied more than once.';
            continue;
        }
        $seen[] = $key;

        if (in_array($key, equity_integration_forbidden_cli_flags(), true) || $key === 'app-config-cache') {
            $errors[] = 'Secret, socket, cache-path, or connection-string flags are not accepted.';
            continue;
        }

        if ($key === 'env-file') {
            $envFile = $value;
            continue;
        }

        if ($key === 'project-root') {
            $projectRoot = $value;
            continue;
        }

        $errors[] = 'Unknown flag "--'.$key.'" is rejected. Only --env-file and optional --project-root are allowed.';
    }

    if ($envFile === null || equity_integration_is_blank($envFile)) {
        $errors[] = 'Approved env file was not explicitly supplied. Pass --env-file with a non-.env path.';
    }

    return [
        'ok' => $errors === [],
        'env_file' => $envFile,
        'project_root' => $projectRoot,
        'errors' => $errors,
    ];
}

/**
 * @return list<string>
 */
function equity_resolved_unsupported_syntax_errors(string $line, int $lineNumber): array
{
    if (str_contains($line, "\0")) {
        return ['Line '.$lineNumber.': NUL bytes are rejected.'];
    }

    if (preg_match('/[`$]|\$\(|\$\{/', $line) === 1) {
        return ['Line '.$lineNumber.': interpolation or command substitution is unsupported and rejected.'];
    }

    if (str_contains($line, '\\')) {
        return ['Line '.$lineNumber.': escape sequences are unsupported and rejected.'];
    }

    if (preg_match('/^\s*export\s+/', $line) === 1) {
        return ['Line '.$lineNumber.': export syntax is unsupported and rejected.'];
    }

    if (str_contains($line, ' #') || str_contains($line, '\t#')) {
        return ['Line '.$lineNumber.': inline comments are unsupported and rejected.'];
    }

    return [];
}

/**
 * @return array{ok: bool, values: array<string, string>, secret_keys: list<string>, errors: list<string>}
 */
function equity_resolved_parse_env_contents(string $contents): array
{
    $errors = [];
    $values = [];
    $secretKeys = [];

    if (str_starts_with($contents, "\xEF\xBB\xBF")) {
        return [
            'ok' => false,
            'values' => [],
            'secret_keys' => [],
            'errors' => ['UTF-8 BOM is unsupported and rejected.'],
        ];
    }

    if (str_contains($contents, "\0")) {
        return [
            'ok' => false,
            'values' => [],
            'secret_keys' => [],
            'errors' => ['Binary NUL content is rejected.'],
        ];
    }

    $lines = preg_split("/\r\n|\n|\r/", $contents);
    if ($lines === false) {
        return [
            'ok' => false,
            'values' => [],
            'secret_keys' => [],
            'errors' => ['Env file could not be split into lines.'],
        ];
    }

    foreach ($lines as $index => $line) {
        $lineNumber = $index + 1;
        if ($line === '') {
            continue;
        }

        if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
            if ($line !== trim($line) && str_starts_with(ltrim($line), '#')) {
                $errors[] = 'Line '.$lineNumber.': indented comments are unsupported and rejected.';
            }
            continue;
        }

        $syntaxErrors = equity_resolved_unsupported_syntax_errors($line, $lineNumber);
        if ($syntaxErrors !== []) {
            $errors = array_merge($errors, $syntaxErrors);
            continue;
        }

        if (!str_contains($line, '=')) {
            $errors[] = 'Line '.$lineNumber.': malformed line. Only KEY=VALUE is supported.';
            continue;
        }

        [$key, $value] = explode('=', $line, 2);

        if ($key !== trim($key) || $value !== trim($value) || $key === '') {
            $errors[] = 'Line '.$lineNumber.': padded keys/values and spaces around = are rejected.';
            continue;
        }

        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
            $errors[] = 'Line '.$lineNumber.': key format is unsupported. Use unquoted uppercase names.';
            continue;
        }

        if (preg_match('/^[\'"].*[\'"]$/', $value) === 1) {
            $errors[] = 'Line '.$lineNumber.': quoted values are unsupported and rejected.';
            continue;
        }

        if (equity_resolved_is_forbidden_env_key($key)) {
            $errors[] = 'Forbidden or unverifiable key '.$key.' is rejected.';
            continue;
        }

        if (! equity_resolved_is_allowed_env_key($key)) {
            $errors[] = 'Unexpected key '.$key.' is rejected. This checker uses a narrow KEY=VALUE allowlist.';
            continue;
        }

        if (array_key_exists($key, $values) || in_array($key, $secretKeys, true)) {
            $errors[] = 'Duplicate key '.$key.' is rejected.';
            continue;
        }

        if (in_array($key, equity_resolved_optional_secret_keys(), true)) {
            $secretKeys[] = $key;
            continue;
        }

        $values[$key] = $value;
    }

    return [
        'ok' => $errors === [],
        'values' => $values,
        'secret_keys' => $secretKeys,
        'errors' => $errors,
    ];
}

/**
 * @param array<string, string> $envValues
 * @return array<string, string>
 */
function equity_resolved_to_preflight_values(array $envValues): array
{
    $mapped = [];
    if (array_key_exists('DB_DATABASE', $envValues)) {
        $mapped['database'] = $envValues['DB_DATABASE'];
    }
    if (array_key_exists('DB_CONNECTION', $envValues)) {
        $mapped['driver'] = $envValues['DB_CONNECTION'];
    }
    if (array_key_exists('APP_ENV', $envValues)) {
        $mapped['app-env'] = $envValues['APP_ENV'];
    }
    if (array_key_exists('DB_HOST', $envValues)) {
        $mapped['host'] = $envValues['DB_HOST'];
    }
    if (array_key_exists('DB_PORT', $envValues)) {
        $mapped['port'] = $envValues['DB_PORT'];
    }
    if (array_key_exists('DB_USERNAME', $envValues)) {
        $mapped['username'] = $envValues['DB_USERNAME'];
    }

    return $mapped;
}

/**
 * Canonicalize an existing directory. Does not require the path to be the repository root.
 *
 * @return array{path: ?string, errors: list<string>}
 */
function equity_resolved_canonicalize_directory(string $requested): array
{
    if ($requested !== trim($requested) || equity_integration_is_blank($requested)) {
        return ['path' => null, 'errors' => ['Project root is missing or whitespace-padded.']];
    }

    if (!is_dir($requested)) {
        return ['path' => null, 'errors' => ['Project root is not an existing directory.']];
    }

    $realRequested = realpath($requested);
    if ($realRequested === false) {
        return ['path' => null, 'errors' => ['Project root could not be canonicalized. Ambiguous symlinks or junctions are rejected.']];
    }

    return ['path' => $realRequested, 'errors' => []];
}

/**
 * Bind a requested project root to the canonical repository root. No caller-identity bypass.
 *
 * @return array{root: ?string, errors: list<string>}
 */
function equity_resolved_bind_project_root(string $requested): array
{
    $canonical = equity_resolved_canonical_repository_root();
    if ($canonical === false) {
        return ['root' => null, 'errors' => ['Canonical repository root could not be resolved.']];
    }

    $resolved = equity_resolved_canonicalize_directory($requested);
    if ($resolved['errors'] !== []) {
        return ['root' => null, 'errors' => $resolved['errors']];
    }

    if (equity_resolved_paths_equal((string) $resolved['path'], $canonical)) {
        return ['root' => $resolved['path'], 'errors' => []];
    }

    return [
        'root' => null,
        'errors' => ['Unbound --project-root is rejected. Ordinary use must target the canonical repository root derived from this script.'],
    ];
}

/**
 * Always inspects the canonical repository cache path. Never deletes a cache file.
 *
 * @param array<string, string> $envValues
 * @return list<string>
 */
function equity_resolved_check_cache_state(string $projectRoot, array $envValues): array
{
    $errors = [];
    $canonical = equity_resolved_canonical_repository_root();
    if ($canonical === false) {
        return ['Canonical repository root could not be resolved for cache inspection.'];
    }

    if (array_key_exists('APP_CONFIG_CACHE', $envValues)) {
        $errors[] = 'APP_CONFIG_CACHE is rejected. Alternative config cache paths cannot be verified safely.';
    }

    if (is_file(equity_resolved_config_cache_path($canonical))) {
        $errors[] = 'Laravel config cache exists at the canonical repository '.EQUITY_RESOLVED_CONFIG_CACHE_RELATIVE.'. Cached configuration is rejected.';
    }

    if (! equity_resolved_paths_equal($projectRoot, $canonical)
        && is_file(equity_resolved_config_cache_path($projectRoot))) {
        $errors[] = 'Laravel config cache exists under the synthetic project root. Cached configuration is rejected.';
    }

    return $errors;
}

function equity_resolved_usage(): string
{
    return implode("\n", [
        'Usage:',
        '  php scripts/integration-resolved-config.php --env-file=path/to/sample.env.integration',
        '',
        'Ordinary use is bound to the canonical repository root derived from this script.',
        '--project-root pointing outside that root is rejected.',
        'Inherited process DB_*, APP_ENV, APP_CONFIG_CACHE, and related selectors fail closed by presence.',
        'Never pass the protected .env. Never pass passwords or URLs on the command line.',
        'This command never bootstraps Laravel and never connects to a database.',
    ]);
}

/**
 * @param array<string, string> $envValues
 * @param list<string> $secretKeys
 * @param list<string> $errors
 * @return list<string>
 */
function equity_resolved_format_report(
    ?string $envFile,
    string $projectRoot,
    array $envValues,
    array $secretKeys,
    array $errors,
): array {
    $lines = [];
    $passed = $errors === [];
    $lines[] = 'Equity integration resolved-config: '.($passed ? 'PASS' : 'FAIL');
    $lines[] = 'Mode: offline env-file and cache-state check (no database connection, no Laravel bootstrap).';
    $lines[] = 'Layer: 2 (explicit env-file validation).';
    $lines[] = 'env-file='.($envFile === null ? '(missing)' : basename($envFile));
    $lines[] = 'project-root-basename='.basename($projectRoot);
    $lines[] = 'config-cache='.EQUITY_RESOLVED_CONFIG_CACHE_RELATIVE;
    $lines[] = 'APP_ENV='.($envValues['APP_ENV'] ?? '(missing)');
    $lines[] = 'DB_CONNECTION='.($envValues['DB_CONNECTION'] ?? '(missing)');
    $lines[] = 'DB_DATABASE='.($envValues['DB_DATABASE'] ?? '(missing)');
    $lines[] = 'DB_HOST='.($envValues['DB_HOST'] ?? '(missing)');
    $lines[] = 'DB_PORT='.($envValues['DB_PORT'] ?? '(missing)');
    $lines[] = 'DB_USERNAME='.($envValues['DB_USERNAME'] ?? '(missing)');
    $lines[] = 'DB_PASSWORD='.(in_array('DB_PASSWORD', $secretKeys, true) ? '(withheld)' : '(absent)');

    if ($passed) {
        $lines[] = 'Env-file identifiers match the isolation contract, inherited process selectors were absent, and no canonical config cache file is present.';
        $lines[] = 'Limitation: this check validates an explicit file and cache presence. It does not prove Laravel\'s runtime resolved connection after bootstrap.';
        $lines[] = 'Layer 3 connected identity/privilege verification is NOT IMPLEMENTED.';
    } else {
        foreach ($errors as $error) {
            $lines[] = 'FAIL: '.$error;
        }
    }

    return $lines;
}

/**
 * Inspect an explicit env file against an explicit project-root argument.
 * Does not read process environment and does not bind the root to the repository.
 * Always inspects the canonical repository config cache. Never opens protected .env.
 *
 * @return array{env_file: ?string, project_root: string, values: array<string, string>, secret_keys: list<string>, errors: list<string>}
 */
function equity_resolved_inspect_env_file(?string $envFile, string $projectRoot): array
{
    $errors = [];
    $envValues = [];
    $secretKeys = [];

    $canonicalRoot = equity_resolved_canonical_repository_root();
    $canonicalEnv = $canonicalRoot === false ? null : $canonicalRoot.DIRECTORY_SEPARATOR.'.env';

    $resolvedRoot = equity_resolved_canonicalize_directory($projectRoot);
    $errors = array_merge($errors, $resolvedRoot['errors']);
    if ($resolvedRoot['path'] !== null) {
        $projectRoot = $resolvedRoot['path'];
    }

    if ($envFile === null || equity_integration_is_blank($envFile)) {
        $errors[] = 'Approved env file was not explicitly supplied. Pass --env-file with a non-.env path.';
    } elseif ($envFile !== trim($envFile)) {
        $errors[] = 'Env file path is whitespace-padded and rejected.';
    } elseif (equity_resolved_is_protected_env_path($envFile, $projectRoot)
        || ($canonicalRoot !== false && equity_resolved_is_protected_env_path($envFile, $canonicalRoot))) {
        $errors[] = 'Protected .env path is rejected and was not opened.';
    } else {
        $absolute = $envFile;
        if (!preg_match('/^[A-Za-z]:[\\\\\\/]/', $envFile) && !str_starts_with($envFile, '/') && !str_starts_with($envFile, '\\')) {
            $absolute = $projectRoot.DIRECTORY_SEPARATOR.$envFile;
        }

        if (equity_resolved_is_protected_env_path($absolute, $projectRoot)
            || ($canonicalRoot !== false && equity_resolved_is_protected_env_path($absolute, $canonicalRoot))) {
            $errors[] = 'Protected .env path is rejected and was not opened.';
        } elseif (!is_file($absolute) || !is_readable($absolute)) {
            $errors[] = 'Env file does not exist or is not readable.';
        } else {
            $realEnv = realpath($absolute);
            if ($realEnv === false) {
                $errors[] = 'Env file path could not be canonicalized. Ambiguous symlinks or junctions are rejected.';
            } elseif (strcasecmp(basename($realEnv), '.env') === 0
                || ($canonicalEnv !== null && equity_resolved_paths_equal($realEnv, $canonicalEnv))) {
                $errors[] = 'Protected .env path is rejected and was not opened.';
            } else {
                $size = filesize($absolute);
                if ($size === false || $size > EQUITY_RESOLVED_MAX_ENV_BYTES) {
                    $errors[] = 'Env file is missing or larger than the supported size limit.';
                } else {
                    $contents = file_get_contents($absolute);
                    if ($contents === false) {
                        $errors[] = 'Env file could not be read.';
                    } else {
                        $parsedFile = equity_resolved_parse_env_contents($contents);
                        $envValues = $parsedFile['values'];
                        $secretKeys = $parsedFile['secret_keys'];
                        $errors = array_merge($errors, $parsedFile['errors']);
                    }
                }
            }
        }
    }

    $errors = array_merge($errors, equity_resolved_check_cache_state($projectRoot, $envValues));

    if ($errors === []) {
        foreach (equity_resolved_required_env_keys() as $key) {
            if (!array_key_exists($key, $envValues)) {
                $errors[] = 'Missing required key '.$key.'.';
            }
        }
    }

    if ($errors === []) {
        $errors = array_merge($errors, equity_integration_validate(equity_resolved_to_preflight_values($envValues)));
    }

    return [
        'env_file' => $envFile,
        'project_root' => $projectRoot,
        'values' => $envValues,
        'secret_keys' => $secretKeys,
        'errors' => $errors,
    ];
}

/**
 * CLI entry: always rejects inherited process selectors and unbound project roots.
 *
 * @param list<string> $argv
 * @return array{exit_code: int, output: string, values: array<string, string>, errors: list<string>}
 */
function equity_resolved_config(array $argv): array
{
    $args = array_slice($argv, 1);
    if ($args === ['--help'] || $args === ['-h']) {
        return [
            'exit_code' => 0,
            'output' => equity_resolved_usage()."\n",
            'values' => [],
            'errors' => [],
        ];
    }

    $parsedArgs = equity_resolved_parse_argv($argv);
    $envFile = $parsedArgs['env_file'];
    $projectRoot = $parsedArgs['project_root'];
    $errors = array_merge($parsedArgs['errors'], equity_resolved_inherited_environment_errors());
    $envValues = [];
    $secretKeys = [];

    $bound = equity_resolved_bind_project_root($projectRoot);
    $errors = array_merge($errors, $bound['errors']);
    if ($bound['root'] !== null) {
        $projectRoot = $bound['root'];
    }

    if ($errors === [] && $envFile !== null) {
        $inspected = equity_resolved_inspect_env_file($envFile, $projectRoot);
        $envValues = $inspected['values'];
        $secretKeys = $inspected['secret_keys'];
        $projectRoot = $inspected['project_root'];
        $errors = array_merge($errors, $inspected['errors']);
    } else {
        $errors = array_merge($errors, equity_resolved_check_cache_state($projectRoot, $envValues));
    }

    $lines = equity_resolved_format_report($envFile, $projectRoot, $envValues, $secretKeys, $errors);

    return [
        'exit_code' => $errors === [] ? 0 : 1,
        'output' => implode("\n", $lines)."\n",
        'values' => $envValues,
        'errors' => $errors,
    ];
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME'])
    && basename((string) $_SERVER['SCRIPT_FILENAME']) === 'integration-resolved-config.php') {
    $result = equity_resolved_config($argv);
    fwrite($result['exit_code'] === 0 ? STDOUT : STDERR, $result['output']);
    exit($result['exit_code']);
}
