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

/**
 * @param list<string> $args
 * @param array<string, string> $extraEnv
 * @return array{exit_code: int, output: string}
 */
function equity_it_spawn(string $script, array $args, array $extraEnv = []): array
{
    $command = array_merge([PHP_BINARY, $script], $args);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $env = [
        'PATH' => (string) (getenv('PATH') ?: ''),
        'PATHEXT' => (string) (getenv('PATHEXT') ?: '.COM;.EXE;.BAT;.CMD'),
        'SystemRoot' => (string) (getenv('SystemRoot') ?: 'C:\\Windows'),
        'SystemDrive' => (string) (getenv('SystemDrive') ?: 'C:'),
        'COMSPEC' => (string) (getenv('COMSPEC') ?: ''),
        'TEMP' => (string) (getenv('TEMP') ?: sys_get_temp_dir()),
        'TMP' => (string) (getenv('TMP') ?: sys_get_temp_dir()),
        'DB_DATABASE' => 'equity',
        'DB_PASSWORD' => 's3cret-value',
        'APP_ENV' => 'production',
    ];
    $env = array_merge($env, $extraEnv);

    $pipes = [];
    $process = proc_open($command, $descriptors, $pipes, dirname($script, 2), $env);
    if (!is_resource($process)) {
        return ['exit_code' => 99, 'output' => 'proc_open failed'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    return [
        'exit_code' => $code,
        'output' => (string) $stdout.(string) $stderr,
    ];
}

function equity_it_run(array $flags): array
{
    return equity_integration_preflight(array_merge(['integration-preflight.php'], $flags));
}

function equity_it_valid(array $overrides = []): array
{
    $flags = [
        '--database='.EQUITY_INTEGRATION_APPROVED_DATABASE,
        '--driver=mariadb',
        '--app-env=integration',
        '--host=127.0.0.1',
        '--port=3306',
        '--username='.EQUITY_INTEGRATION_APPROVED_USERNAME,
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
    foreach (['s3cret-value', 'DB_PASSWORD', 'MYSQL_PWD', 'password=', '://', 'mysql://', 'mariadb://'] as $needle) {
        if (stripos($output, $needle) !== false) {
            return true;
        }
    }

    return false;
}

function equity_it_source_is_offline(string $source): bool
{
    return preg_match('/new PDO|\\bPDO\\b|mysqli_|SQLite3|getenv\\(|\\$_ENV|Illuminate\\\\|artisan|mysql_connect|pg_connect|PDO::/i', $source) === 0;
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

equity_it_assert('no Laravel bootstrap is required', $bootstrapped === false);

$source = (string) file_get_contents($preflightPath);
equity_it_assert('preflight source has no database or env fallback APIs', equity_it_source_is_offline($source));

$accepted = equity_it_run(equity_it_valid());
equity_it_assert('exact approved disposable name is accepted', $accepted['exit_code'] === 0, $accepted['output']);
equity_it_assert('accepted report does not print secrets', equity_it_output_has_secret($accepted['output']) === false, $accepted['output']);

$protected = equity_it_run(equity_it_valid(['database' => 'equity']));
equity_it_assert(
    'protected equity name is rejected',
    $protected['exit_code'] === 1 && str_contains(strtolower($protected['output']), 'protected'),
    $protected['output'],
);

$missingDatabase = equity_it_run(equity_it_valid(['database' => null]));
equity_it_assert(
    'missing database name is rejected',
    $missingDatabase['exit_code'] === 1 && str_contains($missingDatabase['output'], 'was not explicitly supplied'),
    $missingDatabase['output'],
);

$wrongName = equity_it_run(equity_it_valid(['database' => 'equity_it_6c2']));
equity_it_assert(
    'wrong disposable database name is rejected',
    $wrongName['exit_code'] === 1 && str_contains($wrongName['output'], EQUITY_INTEGRATION_APPROVED_DATABASE),
    $wrongName['output'],
);

$padded = equity_it_run(equity_it_valid(['database' => EQUITY_INTEGRATION_APPROVED_DATABASE.' ']));
equity_it_assert(
    'padded database flag is rejected',
    $padded['exit_code'] === 1 && str_contains($padded['output'], 'whitespace'),
    $padded['output'],
);

$duplicate = equity_it_run(array_merge(equity_it_valid(), ['--host=127.0.0.1']));
equity_it_assert(
    'duplicated flags are rejected',
    $duplicate['exit_code'] === 1 && str_contains($duplicate['output'], 'more than once'),
    $duplicate['output'],
);

$wrongDriver = equity_it_run(equity_it_valid(['driver' => 'sqlite']));
equity_it_assert('incorrect driver is rejected', $wrongDriver['exit_code'] === 1, $wrongDriver['output']);

$missingDriver = equity_it_run(equity_it_valid(['driver' => null]));
equity_it_assert('missing driver is rejected', $missingDriver['exit_code'] === 1, $missingDriver['output']);

$production = equity_it_run(equity_it_valid(['app-env' => 'production']));
equity_it_assert('production APP_ENV is rejected', $production['exit_code'] === 1, $production['output']);

foreach (['host' => null, 'port' => null, 'username' => null] as $key => $value) {
    $result = equity_it_run(equity_it_valid([$key => $value]));
    equity_it_assert('missing --'.$key.' is rejected', $result['exit_code'] === 1, $result['output']);
}

$blankDatabase = equity_it_run(equity_it_valid(['database' => '   ']));
equity_it_assert('whitespace-only database name is rejected', $blankDatabase['exit_code'] === 1, $blankDatabase['output']);

$emptyHost = equity_it_run(equity_it_valid(['host' => '']));
equity_it_assert('empty host is rejected', $emptyHost['exit_code'] === 1, $emptyHost['output']);

foreach (['root', 'root@localhost', 'root@%', 'ROOT', 'admin'] as $username) {
    $result = equity_it_run(equity_it_valid(['username' => $username]));
    equity_it_assert(
        'username '.$username.' is rejected',
        $result['exit_code'] === 1,
        $result['output'],
    );
}

$secretFlag = equity_it_run(array_merge(equity_it_valid(), ['--password=s3cret-value']));
equity_it_assert('secret flags are rejected', $secretFlag['exit_code'] === 1, $secretFlag['output']);
equity_it_assert('planted secret is not printed', equity_it_output_has_secret($secretFlag['output']) === false, $secretFlag['output']);

$socketFlag = equity_it_run(array_merge(equity_it_valid(), ['--unix-socket=/tmp/mysql.sock']));
equity_it_assert('socket flags are rejected', $socketFlag['exit_code'] === 1, $socketFlag['output']);

$urlFlag = equity_it_run(array_merge(equity_it_valid(), ['--url=mysql://example.invalid/equity']));
equity_it_assert('URL flags are rejected', $urlFlag['exit_code'] === 1, $urlFlag['output']);
equity_it_assert('URL flag value is not printed', str_contains($urlFlag['output'], 'mysql://') === false, $urlFlag['output']);

$noArgs = equity_it_run([]);
equity_it_assert('execution without explicit approved identity is refused', $noArgs['exit_code'] === 1, $noArgs['output']);

$mixedHelp = equity_it_run(array_merge(['--help'], equity_it_valid()));
equity_it_assert('help mixed with configuration flags is rejected', $mixedHelp['exit_code'] === 1, $mixedHelp['output']);

$cliPass = equity_it_spawn($preflightPath, equity_it_valid());
equity_it_assert(
    'real CLI entrypoint accepts approved flags and ignores process DB_* env',
    $cliPass['exit_code'] === 0 && str_contains($cliPass['output'], 'PASS'),
    $cliPass['output'],
);
equity_it_assert(
    'real CLI entrypoint does not print process-env secrets',
    equity_it_output_has_secret($cliPass['output']) === false,
    $cliPass['output'],
);

$cliFail = equity_it_spawn($preflightPath, equity_it_valid(['database' => 'equity']));
equity_it_assert(
    'real CLI entrypoint returns nonzero for protected equity',
    $cliFail['exit_code'] === 1 && str_contains($cliFail['output'], 'FAIL'),
    $cliFail['output'],
);

echo "\n{$passed} passed, {$failed} failed\n";

if ($failed > 0) {
    fwrite(STDERR, "\nFailed cases:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

exit(0);
