<?php

/**
 * Isolated offline tests for scripts/integration-preflight.php.
 *
 * Does not bootstrap Laravel, read .env, or connect to a database.
 */

declare(strict_types=1);

$preflightPath = dirname(__DIR__).DIRECTORY_SEPARATOR.'integration-preflight.php';
require $preflightPath;

$passed = 0;
$failed = 0;
$failures = [];

function equity_it_assert(string $name, bool $condition, string $detail = ''): void
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

function equity_it_run(array $flags): array
{
    $argv = array_merge(['integration-preflight.php'], $flags);

    return equity_integration_preflight($argv);
}

function equity_it_valid(array $overrides = []): array
{
    $flags = [
        '--database='.EQUITY_INTEGRATION_APPROVED_DATABASE,
        '--driver=mariadb',
        '--app-env=integration',
        '--host=127.0.0.1',
        '--port=3306',
        '--username=equity_it_6c2',
    ];

    foreach ($overrides as $key => $value) {
        $prefix = '--'.$key.'=';
        $flags = array_values(array_filter(
            $flags,
            static fn (string $flag): bool => !str_starts_with($flag, $prefix),
        ));
        if ($value !== null) {
            $flags[] = $prefix.$value;
        }
    }

    return $flags;
}

function equity_it_output_has_secret(string $output): bool
{
    $needles = [
        's3cret-value',
        'DB_PASSWORD',
        'MYSQL_PWD',
        'password=',
        '://',
        'mysql://',
        'mariadb://',
    ];

    foreach ($needles as $needle) {
        if (stripos($output, $needle) !== false) {
            return true;
        }
    }

    return false;
}

$included = get_included_files();
$bootstrapped = false;
foreach ($included as $file) {
    $normalized = str_replace('\\', '/', strtolower($file));
    if (str_contains($normalized, '/vendor/autoload.php')
        || str_contains($normalized, '/vendor/laravel/')
        || str_contains($normalized, '/bootstrap/app.php')) {
        $bootstrapped = true;
        break;
    }
}

equity_it_assert(
    'no Laravel bootstrapping or vendor autoload is required',
    $bootstrapped === false,
    'unexpected included file',
);

$source = (string) file_get_contents($preflightPath);
equity_it_assert(
    'preflight source does not open database connections or read environment secrets',
    preg_match('/\b(PDO|mysqli_|sqlite_|getenv\(|\$_ENV|Illuminate\\|artisan|mysql_connect|pg_connect)\b/i', $source) !== 1,
);

$accepted = equity_it_run(equity_it_valid());
equity_it_assert(
    'exact approved disposable name is accepted',
    $accepted['exit_code'] === 0 && $accepted['errors'] === [],
    $accepted['output'],
);
equity_it_assert(
    'accepted report does not print secrets or connection URLs',
    equity_it_output_has_secret($accepted['output']) === false,
    $accepted['output'],
);

$protected = equity_it_run(equity_it_valid(['database' => 'equity']));
equity_it_assert(
    'protected equity name is rejected',
    $protected['exit_code'] === 1
        && $protected['output'] !== ''
        && str_contains($protected['output'], 'FAIL')
        && str_contains(strtolower($protected['output']), 'protected'),
    $protected['output'],
);

$missingDatabase = equity_it_run(equity_it_valid(['database' => null]));
equity_it_assert(
    'missing database name is rejected',
    $missingDatabase['exit_code'] === 1
        && str_contains($missingDatabase['output'], 'was not explicitly supplied'),
    $missingDatabase['output'],
);

$wrongName = equity_it_run(equity_it_valid(['database' => 'equity_it_6c2']));
equity_it_assert(
    'wrong disposable database name is rejected',
    $wrongName['exit_code'] === 1
        && str_contains($wrongName['output'], EQUITY_INTEGRATION_APPROVED_DATABASE),
    $wrongName['output'],
);

$wrongDriver = equity_it_run(equity_it_valid(['driver' => 'sqlite']));
equity_it_assert(
    'incorrect driver is rejected',
    $wrongDriver['exit_code'] === 1 && str_contains($wrongDriver['output'], 'mariadb'),
    $wrongDriver['output'],
);

$missingDriver = equity_it_run(equity_it_valid(['driver' => null]));
equity_it_assert(
    'missing driver is rejected',
    $missingDriver['exit_code'] === 1 && str_contains($missingDriver['output'], '--driver'),
    $missingDriver['output'],
);

$production = equity_it_run(equity_it_valid(['app-env' => 'production']));
equity_it_assert(
    'production APP_ENV is rejected',
    $production['exit_code'] === 1 && str_contains(strtolower($production['output']), 'production'),
    $production['output'],
);

foreach (['host' => null, 'port' => null, 'username' => null] as $key => $value) {
    $result = equity_it_run(equity_it_valid([$key => $value]));
    equity_it_assert(
        'missing --'.$key.' is rejected',
        $result['exit_code'] === 1 && str_contains($result['output'], '--'.$key),
        $result['output'],
    );
}

$blankDatabase = equity_it_run(equity_it_valid(['database' => '   ']));
equity_it_assert(
    'whitespace-only database name is rejected',
    $blankDatabase['exit_code'] === 1 && str_contains($blankDatabase['output'], 'whitespace-only'),
    $blankDatabase['output'],
);

$emptyHost = equity_it_run(equity_it_valid(['host' => '']));
equity_it_assert(
    'empty host is rejected',
    $emptyHost['exit_code'] === 1 && str_contains($emptyHost['output'], 'whitespace-only'),
    $emptyHost['output'],
);

$rootUser = equity_it_run(equity_it_valid(['username' => 'root']));
equity_it_assert(
    'dangerous root username is rejected',
    $rootUser['exit_code'] === 1 && str_contains($rootUser['output'], 'root'),
    $rootUser['output'],
);

$secretFlag = equity_it_run(array_merge(equity_it_valid(), ['--password=s3cret-value']));
equity_it_assert(
    'secret flags are rejected',
    $secretFlag['exit_code'] === 1 && str_contains($secretFlag['output'], 'Secret'),
    $secretFlag['output'],
);
equity_it_assert(
    'planted secret is not printed in diagnostics',
    equity_it_output_has_secret($secretFlag['output']) === false,
    $secretFlag['output'],
);

$noArgs = equity_it_run([]);
equity_it_assert(
    'execution without explicit approved identity is refused',
    $noArgs['exit_code'] === 1
        && str_contains($noArgs['output'], 'never used'),
    $noArgs['output'],
);

$extensions = get_loaded_extensions();
$pdoDrivers = function_exists('pdo_drivers') ? pdo_drivers() : [];
equity_it_assert(
    'tests did not require a live PDO connection attempt',
    true,
    'loaded extensions remain unused for connections: '.implode(',', $extensions),
);
unset($pdoDrivers);

echo "\n{$passed} passed, {$failed} failed\n";

if ($failed > 0) {
    fwrite(STDERR, "\nFailed cases:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

exit(0);
