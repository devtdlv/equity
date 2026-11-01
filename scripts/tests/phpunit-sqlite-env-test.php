<?php

/**
 * Offline PHPUnit XML environment-precedence tests for SQLite test safety.
 *
 * Uses PHPUnit 11.5 configuration classes only. Does not bootstrap Laravel,
 * run Pest/application tests, or connect to a database.
 */

declare(strict_types=1);

$repoRoot = dirname(__DIR__, 2);
$phpunitXml = $repoRoot.DIRECTORY_SEPARATOR.'phpunit.xml';
$autoload = $repoRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';
$phpHandlerSource = $repoRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'phpunit'.DIRECTORY_SEPARATOR.'phpunit'.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'TextUI'.DIRECTORY_SEPARATOR.'Configuration'.DIRECTORY_SEPARATOR.'PhpHandler.php';
$schemaPath = $repoRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'phpunit'.DIRECTORY_SEPARATOR.'phpunit'.DIRECTORY_SEPARATOR.'phpunit.xsd';

$passed = 0;
$failed = 0;
$failures = [];

function equity_phpunit_assert(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed, $failures;

    if ($condition) {
        $passed++;
        echo 'PASS  '.$name."\n";

        return;
    }

    $failed++;
    $message = $name.($detail !== '' ? ': '.$detail : '');
    $failures[] = $message;
    echo 'FAIL  '.$message."\n";
}

/**
 * Laravel Env read order analogue: $_SERVER, then $_ENV, then getenv.
 */
function equity_phpunit_laravel_env_read(array $server, array $env, array $getenvValues, string $key): string|false
{
    if (array_key_exists($key, $server) && ! is_array($server[$key])) {
        return (string) $server[$key];
    }

    if (array_key_exists($key, $env)) {
        return (string) $env[$key];
    }

    return $getenvValues[$key] ?? false;
}

/**
 * @param array<string, string> $extraEnv
 * @return array{exit_code: int, output: string}
 */
function equity_phpunit_spawn(string $script, array $args, array $extraEnv, string $cwd): array
{
    $command = array_merge([PHP_BINARY, $script], $args);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $env = array_merge([
        'PATH' => (string) (getenv('PATH') ?: ''),
        'PATHEXT' => (string) (getenv('PATHEXT') ?: '.COM;.EXE;.BAT;.CMD'),
        'SystemRoot' => (string) (getenv('SystemRoot') ?: 'C:\\Windows'),
        'SystemDrive' => (string) (getenv('SystemDrive') ?: 'C:'),
        'COMSPEC' => (string) (getenv('COMSPEC') ?: ''),
        'TEMP' => (string) (getenv('TEMP') ?: sys_get_temp_dir()),
        'TMP' => (string) (getenv('TMP') ?: sys_get_temp_dir()),
    ], $extraEnv);

    $pipes = [];
    $process = proc_open($command, $descriptors, $pipes, $cwd, $env);
    if (! is_resource($process)) {
        return ['exit_code' => 99, 'output' => 'proc_open failed'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exit_code' => proc_close($process),
        'output' => (string) $stdout.(string) $stderr,
    ];
}

$forcedEnvNames = [
    'APP_ENV',
    'DB_CONNECTION',
    'DB_DATABASE',
    'DB_URL',
    'DATABASE_URL',
    'DB_SOCKET',
    'DB_UNIX_SOCKET',
    'DB_HOST',
    'DB_PORT',
    'DB_USERNAME',
    'DB_PASSWORD',
    'DB_DSN',
    'MYSQL_PWD',
    'APP_CONFIG_CACHE',
];

$hostileEnv = [
    'APP_ENV' => 'production',
    'DB_CONNECTION' => 'mariadb',
    'DB_DATABASE' => 'equity',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_USERNAME' => 'root',
    'DB_PASSWORD' => 's3cret-value',
    'DB_URL' => 'mysql://example.invalid/equity',
    'DATABASE_URL' => 'mysql://example.invalid/equity',
    'DB_SOCKET' => '/tmp/mysql.sock',
    'DB_UNIX_SOCKET' => '/tmp/mysql.sock',
    'DB_DSN' => 'mysql:host=127.0.0.1;dbname=equity',
    'MYSQL_PWD' => 's3cret-value',
    'APP_CONFIG_CACHE' => 'C:\\hostile-cache\\config.php',
];

equity_phpunit_assert('phpunit.xml exists', is_file($phpunitXml));
equity_phpunit_assert('PHPUnit PhpHandler source is present for force-semantics inspection', is_file($phpHandlerSource));

$xml = new DOMDocument;
$xml->preserveWhiteSpace = false;
$loaded = $xml->load($phpunitXml);
equity_phpunit_assert('phpunit.xml is well-formed XML', $loaded !== false);

if (is_file($schemaPath)) {
    $previous = libxml_use_internal_errors(true);
    $valid = $xml->schemaValidate($schemaPath);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $detail = '';
    if ($errors !== []) {
        $detail = trim($errors[0]->message);
    }
    equity_phpunit_assert('phpunit.xml validates against the installed PHPUnit XSD', $valid === true, $detail);
}

$phpHandler = (string) file_get_contents($phpHandlerSource);
equity_phpunit_assert(
    'PHPUnit 11.5 env force overwrites getenv only when force is true or the key is absent',
    str_contains($phpHandler, 'if ($force || getenv($name) === false)')
        && str_contains($phpHandler, 'putenv("{$name}={$value}")'),
);
equity_phpunit_assert(
    'PHPUnit 11.5 env force does not assign $_SERVER',
    str_contains($phpHandler, 'private function handleEnvVariables')
        && ! str_contains($phpHandler, '$_SERVER[$name] = $value'),
);
equity_phpunit_assert(
    'PHPUnit 11.5 server variables always overwrite $_SERVER',
    str_contains($phpHandler, '$_SERVER[$variable->name()] = $variable->value()'),
);

$xpath = new DOMXPath($xml);
$envByName = [];
foreach ($xpath->query('/phpunit/php/env') as $node) {
    if (! $node instanceof DOMElement) {
        continue;
    }
    $envByName[$node->getAttribute('name')] = $node;
}
$serverByName = [];
foreach ($xpath->query('/phpunit/php/server') as $node) {
    if (! $node instanceof DOMElement) {
        continue;
    }
    $serverByName[$node->getAttribute('name')] = $node;
}

equity_phpunit_assert('APP_ENV is forced to testing', ($envByName['APP_ENV']?->getAttribute('value') ?? '') === 'testing' && ($envByName['APP_ENV']?->getAttribute('force') ?? '') === 'true');
equity_phpunit_assert('DB_CONNECTION is forced to sqlite', ($envByName['DB_CONNECTION']?->getAttribute('value') ?? '') === 'sqlite' && ($envByName['DB_CONNECTION']?->getAttribute('force') ?? '') === 'true');
equity_phpunit_assert('DB_DATABASE is forced to :memory:', ($envByName['DB_DATABASE']?->getAttribute('value') ?? '') === ':memory:' && ($envByName['DB_DATABASE']?->getAttribute('force') ?? '') === 'true');

foreach ($forcedEnvNames as $name) {
    $envNode = $envByName[$name] ?? null;
    $serverNode = $serverByName[$name] ?? null;
    equity_phpunit_assert(
        $name.' has force=true env and matching server overwrite',
        $envNode instanceof DOMElement
            && $envNode->getAttribute('force') === 'true'
            && $serverNode instanceof DOMElement
            && $serverNode->getAttribute('value') === $envNode->getAttribute('value'),
    );
}

foreach (['DB_URL', 'DATABASE_URL', 'DB_SOCKET', 'DB_UNIX_SOCKET', 'DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD', 'DB_DSN', 'MYSQL_PWD'] as $name) {
    equity_phpunit_assert(
        $name.' is neutralized to an empty value',
        ($envByName[$name]?->getAttribute('value') ?? 'missing') === '',
    );
}

equity_phpunit_assert(
    'APP_CONFIG_CACHE is forced off the default cache file',
    ($envByName['APP_CONFIG_CACHE']?->getAttribute('value') ?? '') === 'bootstrap/cache/phpunit-config-must-not-exist.php',
);

$sentinel = $repoRoot.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'phpunit-config-must-not-exist.php';
equity_phpunit_assert('sentinel APP_CONFIG_CACHE path does not exist', is_file($sentinel) === false);

$xmlText = (string) file_get_contents($phpunitXml);
equity_phpunit_assert('phpunit.xml does not declare a MariaDB default connection', ! str_contains($xmlText, 'value="mariadb"'));
equity_phpunit_assert('phpunit.xml does not target the protected equity schema as a value', ! preg_match('/value="equity"/', $xmlText));
equity_phpunit_assert('phpunit.xml contains no planted secret values', ! str_contains($xmlText, 's3cret-value') && ! str_contains($xmlText, 'mysql://'));

$included = get_included_files();
$bootstrapped = false;
foreach ($included as $file) {
    $normalized = str_replace('\\', '/', strtolower($file));
    if (str_contains($normalized, '/bootstrap/app.php') || str_contains($normalized, '/vendor/laravel/')) {
        $bootstrapped = true;
        break;
    }
}
equity_phpunit_assert('this offline test does not bootstrap Laravel', $bootstrapped === false);

$runner = sys_get_temp_dir().DIRECTORY_SEPARATOR.'equity-phpunit-env-runner-'.bin2hex(random_bytes(6)).'.php';
$unforcedXml = sys_get_temp_dir().DIRECTORY_SEPARATOR.'equity-phpunit-unforced-'.bin2hex(random_bytes(6)).'.xml';

file_put_contents($unforcedXml, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
>
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
    <php>
        <env name="DB_CONNECTION" value="sqlite"/>
        <env name="DB_DATABASE" value=":memory:"/>
    </php>
</phpunit>
XML);

$runnerCode = <<<'PHP'
<?php
declare(strict_types=1);
$autoload = $argv[1];
$xmlFile = $argv[2];
require $autoload;
$loaded = (new PHPUnit\TextUI\XmlConfiguration\Loader)->load($xmlFile);
(new PHPUnit\TextUI\Configuration\PhpHandler)->handle($loaded->php());
$keys = [
    'APP_ENV', 'DB_CONNECTION', 'DB_DATABASE', 'DB_URL', 'DATABASE_URL',
    'DB_SOCKET', 'DB_UNIX_SOCKET', 'DB_HOST', 'DB_PORT', 'DB_USERNAME',
    'DB_PASSWORD', 'DB_DSN', 'MYSQL_PWD', 'APP_CONFIG_CACHE',
];
$payload = ['getenv' => [], 'env' => [], 'server' => []];
foreach ($keys as $key) {
    $getenv = getenv($key);
    $payload['getenv'][$key] = $getenv === false ? null : $getenv;
    $payload['env'][$key] = array_key_exists($key, $_ENV) ? (string) $_ENV[$key] : null;
    $payload['server'][$key] = array_key_exists($key, $_SERVER) && !is_array($_SERVER[$key]) ? (string) $_SERVER[$key] : null;
}
echo json_encode($payload, JSON_UNESCAPED_SLASHES), "\n";
PHP;
file_put_contents($runner, $runnerCode);

$forcedChild = equity_phpunit_spawn($runner, [$autoload, $phpunitXml], $hostileEnv, $repoRoot);
equity_phpunit_assert(
    'forced PHPUnit XML child exits 0',
    $forcedChild['exit_code'] === 0,
    $forcedChild['output'],
);
equity_phpunit_assert(
    'forced child output does not print planted secrets',
    ! str_contains($forcedChild['output'], 's3cret-value')
        && ! str_contains($forcedChild['output'], 'mysql://example.invalid')
        && ! str_contains($forcedChild['output'], 'hostile-cache'),
    $forcedChild['output'],
);

$forcedPayload = json_decode(trim($forcedChild['output']), true);
equity_phpunit_assert('forced child returned JSON', is_array($forcedPayload));

if (is_array($forcedPayload)) {
    $resolved = [];
    foreach ($forcedEnvNames as $key) {
        $resolved[$key] = equity_phpunit_laravel_env_read(
            $forcedPayload['server'] ?? [],
            $forcedPayload['env'] ?? [],
            array_filter($forcedPayload['getenv'] ?? [], static fn ($value) => $value !== null),
            $key,
        );
    }

    equity_phpunit_assert('hostile inherited APP_ENV is overwritten to testing', $resolved['APP_ENV'] === 'testing');
    equity_phpunit_assert('hostile inherited DB_CONNECTION is overwritten to sqlite', $resolved['DB_CONNECTION'] === 'sqlite');
    equity_phpunit_assert('hostile inherited DB_DATABASE=equity is overwritten to :memory:', $resolved['DB_DATABASE'] === ':memory:');
    equity_phpunit_assert('hostile inherited DB_URL is neutralized', $resolved['DB_URL'] === '');
    equity_phpunit_assert('hostile inherited DATABASE_URL is neutralized', $resolved['DATABASE_URL'] === '');
    equity_phpunit_assert('hostile inherited DB_SOCKET is neutralized', $resolved['DB_SOCKET'] === '');
    equity_phpunit_assert('hostile inherited APP_CONFIG_CACHE is overwritten to the sentinel path', $resolved['APP_CONFIG_CACHE'] === 'bootstrap/cache/phpunit-config-must-not-exist.php');
    equity_phpunit_assert(
        'Laravel-order read of DB_DATABASE is not equity',
        $resolved['DB_DATABASE'] !== 'equity',
    );
}

$unforcedChild = equity_phpunit_spawn($runner, [$autoload, $unforcedXml], [
    'DB_CONNECTION' => 'mariadb',
    'DB_DATABASE' => 'equity',
], $repoRoot);
equity_phpunit_assert('unforced XML child exits 0', $unforcedChild['exit_code'] === 0, $unforcedChild['output']);
$unforcedPayload = json_decode(trim($unforcedChild['output']), true);
if (is_array($unforcedPayload)) {
    $unforcedDatabase = equity_phpunit_laravel_env_read(
        $unforcedPayload['server'] ?? [],
        $unforcedPayload['env'] ?? [],
        array_filter($unforcedPayload['getenv'] ?? [], static fn ($value) => $value !== null),
        'DB_DATABASE',
    );
    $unforcedConnection = equity_phpunit_laravel_env_read(
        $unforcedPayload['server'] ?? [],
        $unforcedPayload['env'] ?? [],
        array_filter($unforcedPayload['getenv'] ?? [], static fn ($value) => $value !== null),
        'DB_CONNECTION',
    );
    equity_phpunit_assert(
        'without force=true, inherited DB_DATABASE=equity is kept',
        $unforcedDatabase === 'equity',
        (string) $unforcedDatabase,
    );
    equity_phpunit_assert(
        'without force=true, inherited DB_CONNECTION=mariadb is kept',
        $unforcedConnection === 'mariadb',
        (string) $unforcedConnection,
    );
}

@unlink($runner);
@unlink($unforcedXml);

echo "\n{$passed} passed, {$failed} failed\n";

if ($failed > 0) {
    fwrite(STDERR, "\nFailed cases:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

exit(0);
