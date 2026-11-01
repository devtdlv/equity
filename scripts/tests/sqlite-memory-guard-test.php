<?php

/**
 * Isolated offline tests for Tests\Support\SqliteMemoryConnectionGuard.
 *
 * Uses synthetic configuration snapshots only. Does not bootstrap Laravel,
 * run Pest, or connect to a database.
 */

declare(strict_types=1);

use Tests\Support\SqliteMemoryConnectionGuard;

$guardPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'SqliteMemoryConnectionGuard.php';
$testCasePath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'TestCase.php';
$lifecyclePath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'laravel'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Illuminate'.DIRECTORY_SEPARATOR.'Foundation'.DIRECTORY_SEPARATOR.'Testing'.DIRECTORY_SEPARATOR.'Concerns'.DIRECTORY_SEPARATOR.'InteractsWithTestCaseLifecycle.php';
$refreshPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'laravel'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Illuminate'.DIRECTORY_SEPARATOR.'Foundation'.DIRECTORY_SEPARATOR.'Testing'.DIRECTORY_SEPARATOR.'RefreshDatabase.php';

require $guardPath;

$passed = 0;
$failed = 0;
$failures = [];

function equity_guard_assert(string $name, bool $condition, string $detail = ''): void
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
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function equity_guard_snapshot(array $overrides = []): array
{
    $sqlite = [
        'driver' => 'sqlite',
        'url' => null,
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ];

    $snapshot = [
        'default' => 'sqlite',
        'connections' => [
            'sqlite' => $sqlite,
            'mariadb' => [
                'driver' => 'mariadb',
                'database' => 'equity',
                'username' => 'root',
                'password' => 's3cret-value',
            ],
        ],
        'transacting_connections' => ['sqlite'],
        'config_loaded_from_cache' => false,
        'resolved_config_cache_exists' => false,
        'default_config_cache_exists' => false,
    ];

    foreach ($overrides as $key => $value) {
        if ($key === 'sqlite' && is_array($value)) {
            $snapshot['connections']['sqlite'] = array_merge($sqlite, $value);
            continue;
        }
        $snapshot[$key] = $value;
    }

    return $snapshot;
}

function equity_guard_has_secret(string $text): bool
{
    foreach (['s3cret-value', 'mysql://', 'mariadb://', '/tmp/mysql.sock', 'example.invalid'] as $needle) {
        if (stripos($text, $needle) !== false) {
            return true;
        }
    }

    return false;
}

$included = get_included_files();
$bootstrapped = false;
foreach ($included as $file) {
    $normalized = str_replace('\\', '/', strtolower($file));
    if (str_contains($normalized, '/bootstrap/app.php')
        || str_contains($normalized, '/vendor/laravel/')
        || str_contains($normalized, '/vendor/autoload.php')) {
        $bootstrapped = true;
        break;
    }
}
equity_guard_assert('no Laravel bootstrap is required', $bootstrapped === false);

$valid = SqliteMemoryConnectionGuard::evaluate(equity_guard_snapshot());
equity_guard_assert('valid SQLite :memory: configuration passes', $valid['ok'] === true, implode('; ', $valid['errors']));

$mariadb = SqliteMemoryConnectionGuard::evaluate(equity_guard_snapshot([
    'default' => 'mariadb',
    'transacting_connections' => ['mariadb'],
]));
equity_guard_assert(
    'MariaDB default connection fails',
    $mariadb['ok'] === false && str_contains(implode(' ', $mariadb['errors']), 'not sqlite'),
    implode('; ', $mariadb['errors']),
);

$equity = SqliteMemoryConnectionGuard::evaluate(equity_guard_snapshot([
    'sqlite' => ['database' => 'equity'],
]));
equity_guard_assert(
    'protected equity database name fails',
    $equity['ok'] === false
        && str_contains(implode(' ', $equity['errors']), ':memory:')
        && str_contains(implode(' ', $equity['errors']), 'equity'),
    implode('; ', $equity['errors']),
);

$fileBacked = SqliteMemoryConnectionGuard::evaluate(equity_guard_snapshot([
    'sqlite' => ['database' => 'database.sqlite'],
]));
equity_guard_assert(
    'SQLite file-backed database fails',
    $fileBacked['ok'] === false && str_contains(implode(' ', $fileBacked['errors']), ':memory:'),
    implode('; ', $fileBacked['errors']),
);

$url = SqliteMemoryConnectionGuard::evaluate(equity_guard_snapshot([
    'sqlite' => ['url' => 'mysql://example.invalid/equity'],
]));
equity_guard_assert(
    'DB_URL on the sqlite connection fails',
    $url['ok'] === false && str_contains(implode(' ', $url['errors']), 'database URL'),
    implode('; ', $url['errors']),
);
equity_guard_assert(
    'DB_URL value is not printed',
    equity_guard_has_secret(implode("\n", $url['errors'])) === false,
    implode('; ', $url['errors']),
);

$emptyUrl = SqliteMemoryConnectionGuard::evaluate(equity_guard_snapshot([
    'sqlite' => ['url' => ''],
]));
equity_guard_assert('empty URL is not treated as a redirect', $emptyUrl['ok'] === true, implode('; ', $emptyUrl['errors']));

$readWrite = SqliteMemoryConnectionGuard::evaluate(equity_guard_snapshot([
    'sqlite' => [
        'read' => ['database' => ':memory:'],
        'write' => ['database' => ':memory:'],
    ],
]));
equity_guard_assert(
    'read/write splitting fails',
    $readWrite['ok'] === false && str_contains(implode(' ', $readWrite['errors']), 'Read/write'),
    implode('; ', $readWrite['errors']),
);

$overrideRaw = (new class
{
    protected function connectionsToTransact()
    {
        return ['sqlite', 'mariadb'];
    }

    public function invoke(): mixed
    {
        return $this->connectionsToTransact();
    }
})->invoke();
$overrideNormalized = SqliteMemoryConnectionGuard::normalizeTransactingConnections($overrideRaw, 'sqlite');
$override = SqliteMemoryConnectionGuard::evaluate(equity_guard_snapshot([
    'transacting_connections' => $overrideNormalized['ok'] ? $overrideNormalized['connections'] : $overrideRaw,
]));
equity_guard_assert(
    'overridden connectionsToTransact returning sqlite and mariadb is rejected',
    $overrideNormalized['ok'] === true
        && $overrideNormalized['connections'] === ['sqlite', 'mariadb']
        && $override['ok'] === false
        && str_contains(implode(' ', $override['errors']), 'transaction connections'),
    implode('; ', $override['errors']),
);

$alternate = SqliteMemoryConnectionGuard::evaluate(equity_guard_snapshot([
    'transacting_connections' => ['sqlite', 'mariadb'],
]));
equity_guard_assert(
    'alternate transaction connections fail',
    $alternate['ok'] === false && str_contains(implode(' ', $alternate['errors']), 'transaction connections'),
    implode('; ', $alternate['errors']),
);

$nullMeansDefault = SqliteMemoryConnectionGuard::normalizeTransactingConnections([null], 'sqlite');
equity_guard_assert(
    'null transaction connection is the default name',
    $nullMeansDefault['ok'] === true && $nullMeansDefault['connections'] === ['sqlite'],
    implode('; ', $nullMeansDefault['errors']),
);

$undetermined = SqliteMemoryConnectionGuard::normalizeTransactingConnections('mariadb', 'sqlite');
equity_guard_assert(
    'non-array transaction connections fail closed',
    $undetermined['ok'] === false && str_contains(implode(' ', $undetermined['errors']), 'could not be determined safely'),
    implode('; ', $undetermined['errors']),
);

$socket = SqliteMemoryConnectionGuard::evaluate(equity_guard_snapshot([
    'sqlite' => ['unix_socket' => '/tmp/mysql.sock'],
]));
equity_guard_assert(
    'unix socket selector fails',
    $socket['ok'] === false && str_contains(implode(' ', $socket['errors']), 'alternate database selector'),
    implode('; ', $socket['errors']),
);
equity_guard_assert(
    'socket path is not printed',
    equity_guard_has_secret(implode("\n", $socket['errors'])) === false,
    implode('; ', $socket['errors']),
);

$missing = SqliteMemoryConnectionGuard::evaluate(['default' => 'sqlite']);
equity_guard_assert(
    'missing snapshot keys fail closed',
    $missing['ok'] === false && str_contains(implode(' ', $missing['errors']), 'malformed'),
    implode('; ', $missing['errors']),
);

$malformedConnections = SqliteMemoryConnectionGuard::evaluate(equity_guard_snapshot([
    'connections' => 'not-an-array',
]));
equity_guard_assert(
    'malformed connections fail closed',
    $malformedConnections['ok'] === false,
    implode('; ', $malformedConnections['errors']),
);

$cached = SqliteMemoryConnectionGuard::evaluate(equity_guard_snapshot([
    'config_loaded_from_cache' => true,
]));
equity_guard_assert(
    'loaded config cache fails',
    $cached['ok'] === false && str_contains(implode(' ', $cached['errors']), 'config cache'),
    implode('; ', $cached['errors']),
);

$resolvedCache = SqliteMemoryConnectionGuard::evaluate(equity_guard_snapshot([
    'resolved_config_cache_exists' => true,
]));
equity_guard_assert(
    'resolved config-cache path presence fails',
    $resolvedCache['ok'] === false && str_contains(implode(' ', $resolvedCache['errors']), 'config cache'),
    implode('; ', $resolvedCache['errors']),
);

$defaultCache = SqliteMemoryConnectionGuard::evaluate(equity_guard_snapshot([
    'default_config_cache_exists' => true,
]));
equity_guard_assert(
    'default config-cache path presence fails',
    $defaultCache['ok'] === false && str_contains(implode(' ', $defaultCache['errors']), 'config cache'),
    implode('; ', $defaultCache['errors']),
);

$secretMessage = SqliteMemoryConnectionGuard::failureMessage($url);
equity_guard_assert(
    'failure message does not print secrets',
    equity_guard_has_secret($secretMessage) === false
        && str_contains($secretMessage, 'SQLite :memory: test guard failed'),
    $secretMessage,
);

$testCaseSource = (string) file_get_contents($testCasePath);
$lifecycleSource = (string) file_get_contents($lifecyclePath);
$refreshSource = (string) file_get_contents($refreshPath);

preg_match('/protected function refreshApplication\(\): void\s*\{(.*?)^\s{4}\}/ms', $testCaseSource, $refreshMatch);
preg_match('/protected function setUpTraits\(\)\s*\{(.*?)^\s{4}\}/ms', $testCaseSource, $traitsMatch);
$refreshBody = $refreshMatch[1] ?? '';
$traitsBody = $traitsMatch[1] ?? '';

equity_guard_assert(
    'TestCase guard runs in refreshApplication after the application is created',
    str_contains($refreshBody, 'parent::refreshApplication()')
        && strpos($refreshBody, 'parent::refreshApplication()') < strpos($refreshBody, '$this->assertSqliteMemoryConnection()'),
    $refreshBody,
);
equity_guard_assert(
    'TestCase guard runs in setUpTraits before parent trait setup',
    str_contains($traitsBody, '$this->assertSqliteMemoryConnection();')
        && str_contains($traitsBody, 'return parent::setUpTraits();')
        && strpos($traitsBody, '$this->assertSqliteMemoryConnection();') < strpos($traitsBody, 'return parent::setUpTraits();'),
    $traitsBody,
);
equity_guard_assert(
    'Laravel setUpTheTestEnvironment creates the application before setUpTraits',
    str_contains($lifecycleSource, '$this->refreshApplication();')
        && str_contains($lifecycleSource, '$this->setUpTraits();')
        && strpos($lifecycleSource, '$this->refreshApplication();') < strpos($lifecycleSource, '$this->setUpTraits();'),
);
equity_guard_assert(
    'Laravel setUpTraits runs RefreshDatabase before migrate:fresh can run',
    str_contains($lifecycleSource, 'if (isset($uses[RefreshDatabase::class]))')
        && str_contains($lifecycleSource, '$this->refreshDatabase();')
        && strpos($lifecycleSource, '$this->setUpTraits();') < strpos($lifecycleSource, '$this->refreshDatabase();'),
);
equity_guard_assert(
    'RefreshDatabase connects only after beforeRefreshingDatabase',
    str_contains($refreshSource, '$this->beforeRefreshingDatabase();')
        && str_contains($refreshSource, '$this->artisan(\'migrate:fresh\'')
        && strpos($refreshSource, '$this->beforeRefreshingDatabase();') < strpos($refreshSource, 'restoreInMemoryDatabase')
        && strpos($refreshSource, '$this->beforeRefreshingDatabase();') < strpos($refreshSource, 'migrate:fresh'),
);
equity_guard_assert(
    'TestCase calls connectionsToTransact() instead of only reading a property',
    str_contains($testCaseSource, 'method_exists($this, \'connectionsToTransact\')')
        && str_contains($testCaseSource, '$this->connectionsToTransact()')
        && ! str_contains($testCaseSource, 'property_exists($this, \'connectionsToTransact\')'),
);
equity_guard_assert(
    'Laravel RefreshDatabase resolves transaction connections via connectionsToTransact()',
    str_contains($refreshSource, 'protected function connectionsToTransact()')
        && str_contains($refreshSource, 'property_exists($this, \'connectionsToTransact\')')
        && str_contains($refreshSource, '[config(\'database.default\')]'),
);
equity_guard_assert(
    'TestCase has no caller-identity or env bypass',
    ! str_contains($testCaseSource, 'debug_backtrace')
        && ! str_contains($testCaseSource, 'test-mode')
        && ! str_contains($testCaseSource, 'EQUITY_SKIP')
        && ! str_contains((string) file_get_contents($guardPath), 'debug_backtrace'),
);

echo "\n{$passed} passed, {$failed} failed\n";

if ($failed > 0) {
    fwrite(STDERR, "\nFailed cases:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

exit(0);
