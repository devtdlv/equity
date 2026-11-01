<?php

/**
 * Isolated offline tests for scripts/integration-resolved-config.php.
 *
 * Uses synthetic fixtures only. Does not bootstrap Laravel, read the protected
 * .env, or connect to a database.
 */

declare(strict_types=1);

$scriptPath = dirname(__DIR__).DIRECTORY_SEPARATOR.'integration-resolved-config.php';
$fixtureDir = __DIR__.DIRECTORY_SEPARATOR.'fixtures';
require $scriptPath;

$passed = 0;
$failed = 0;
$failures = [];

function equity_rc_assert(string $name, bool $condition, string $detail = ''): void
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
function equity_rc_spawn(string $script, array $args, array $extraEnv = []): array
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
        'DB_DATABASE' => 'equity',
        'DB_PASSWORD' => 's3cret-value',
        'APP_ENV' => 'production',
        'APP_CONFIG_CACHE' => 'bootstrap/cache/config.php',
    ], $extraEnv);

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

    return [
        'exit_code' => proc_close($process),
        'output' => (string) $stdout.(string) $stderr,
    ];
}

function equity_rc_run(array $flags): array
{
    return equity_resolved_config(array_merge(['integration-resolved-config.php'], $flags));
}

function equity_rc_fixture(string $name): string
{
    global $fixtureDir;

    return $fixtureDir.DIRECTORY_SEPARATOR.$name;
}

function equity_rc_has_secret(string $output): bool
{
    foreach (['s3cret-value', 'mysql://', 'mariadb://', '/tmp/mysql.sock', 'example.invalid'] as $needle) {
        if (stripos($output, $needle) !== false) {
            return true;
        }
    }

    return false;
}

function equity_rc_source_is_offline(string $source): bool
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

equity_rc_assert('no Laravel bootstrap is required', $bootstrapped === false);
equity_rc_assert(
    'resolved-config source has no database APIs or env fallbacks',
    equity_rc_source_is_offline((string) file_get_contents($scriptPath)),
);

$cleanRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'equity-it-clean-'.bin2hex(random_bytes(6));
mkdir($cleanRoot, 0777, true);
$cleanApproved = $cleanRoot.DIRECTORY_SEPARATOR.'approved.env.integration';
copy(equity_rc_fixture('approved.env.integration'), $cleanApproved);

$approved = equity_rc_run([
    '--env-file='.$cleanApproved,
    '--project-root='.$cleanRoot,
]);
equity_rc_assert('approved fixture passes', $approved['exit_code'] === 0, $approved['output']);

$tempEnv = sys_get_temp_dir().DIRECTORY_SEPARATOR.'equity-it-'.bin2hex(random_bytes(6));
mkdir($tempEnv, 0777, true);
$namedDotEnv = $tempEnv.DIRECTORY_SEPARATOR.'.env';
copy(equity_rc_fixture('approved.env.integration'), $namedDotEnv);
$dotEnv = equity_rc_run(['--env-file='.$namedDotEnv, '--project-root='.$tempEnv]);
equity_rc_assert(
    'file basename .env is rejected without treating it as approved',
    $dotEnv['exit_code'] === 1 && str_contains($dotEnv['output'], 'Protected .env'),
    $dotEnv['output'],
);

$equity = equity_rc_run([
    '--env-file='.equity_rc_fixture('protected-equity.env.integration'),
    '--project-root='.$cleanRoot,
]);
equity_rc_assert(
    'protected database equity is rejected',
    $equity['exit_code'] === 1 && str_contains(strtolower($equity['output']), 'protected'),
    $equity['output'],
);

$wrong = equity_rc_run([
    '--env-file='.equity_rc_fixture('wrong-name.env.integration'),
    '--project-root='.$cleanRoot,
]);
equity_rc_assert(
    'wrong disposable name is rejected',
    $wrong['exit_code'] === 1 && str_contains($wrong['output'], EQUITY_INTEGRATION_APPROVED_DATABASE),
    $wrong['output'],
);

$missing = equity_rc_run([
    '--env-file='.equity_rc_fixture('missing-host.env.integration'),
    '--project-root='.$cleanRoot,
]);
equity_rc_assert(
    'missing required values are rejected',
    $missing['exit_code'] === 1 && str_contains($missing['output'], 'DB_HOST'),
    $missing['output'],
);

$url = equity_rc_run([
    '--env-file='.equity_rc_fixture('with-url.env.integration'),
    '--project-root='.$cleanRoot,
]);
equity_rc_assert('DB_URL is rejected', $url['exit_code'] === 1 && str_contains($url['output'], 'DB_URL'), $url['output']);
equity_rc_assert('DB_URL value is not printed', equity_rc_has_secret($url['output']) === false, $url['output']);

$emptyUrl = equity_rc_run([
    '--env-file='.equity_rc_fixture('empty-url.env.integration'),
    '--project-root='.$cleanRoot,
]);
equity_rc_assert('empty DB_URL assignment is rejected', $emptyUrl['exit_code'] === 1 && str_contains($emptyUrl['output'], 'DB_URL'), $emptyUrl['output']);

$socket = equity_rc_run([
    '--env-file='.equity_rc_fixture('with-socket.env.integration'),
    '--project-root='.$cleanRoot,
]);
equity_rc_assert('DB_SOCKET is rejected', $socket['exit_code'] === 1 && str_contains($socket['output'], 'DB_SOCKET'), $socket['output']);
equity_rc_assert('socket path is not printed', equity_rc_has_secret($socket['output']) === false, $socket['output']);

$cacheRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'equity-it-cache-'.bin2hex(random_bytes(6));
$cacheDir = $cacheRoot.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache';
mkdir($cacheDir, 0777, true);
file_put_contents($cacheDir.DIRECTORY_SEPARATOR.'config.php', "<?php\nreturn [];\n");
$approvedCopy = $cacheRoot.DIRECTORY_SEPARATOR.'approved.env.integration';
copy(equity_rc_fixture('approved.env.integration'), $approvedCopy);
$cached = equity_rc_run([
    '--env-file='.$approvedCopy,
    '--project-root='.$cacheRoot,
]);
equity_rc_assert(
    'cached Laravel configuration is rejected',
    $cached['exit_code'] === 1 && str_contains($cached['output'], 'config cache'),
    $cached['output'],
);

$appCache = equity_rc_run([
    '--env-file='.equity_rc_fixture('app-config-cache.env.integration'),
    '--project-root='.$cleanRoot,
]);
equity_rc_assert(
    'APP_CONFIG_CACHE override is rejected',
    $appCache['exit_code'] === 1 && str_contains($appCache['output'], 'APP_CONFIG_CACHE'),
    $appCache['output'],
);

$duplicate = equity_rc_run([
    '--env-file='.equity_rc_fixture('duplicate-keys.env.integration'),
    '--project-root='.$cleanRoot,
]);
equity_rc_assert(
    'duplicate keys are rejected',
    $duplicate['exit_code'] === 1 && str_contains($duplicate['output'], 'Duplicate'),
    $duplicate['output'],
);

$interpolation = equity_rc_run([
    '--env-file='.equity_rc_fixture('interpolation.env.integration'),
    '--project-root='.$cleanRoot,
]);
equity_rc_assert(
    'interpolation is rejected',
    $interpolation['exit_code'] === 1 && str_contains($interpolation['output'], 'interpolation'),
    $interpolation['output'],
);

$commandSub = $tempEnv.DIRECTORY_SEPARATOR.'command.env.integration';
file_put_contents($commandSub, "APP_ENV=integration\nDB_HOST=`hostname`\n");
$commandResult = equity_rc_run(['--env-file='.$commandSub, '--project-root='.$tempEnv]);
equity_rc_assert(
    'command substitution is rejected',
    $commandResult['exit_code'] === 1 && str_contains($commandResult['output'], 'command substitution'),
    $commandResult['output'],
);

$root = equity_rc_run([
    '--env-file='.equity_rc_fixture('root-user.env.integration'),
    '--project-root='.$cleanRoot,
]);
equity_rc_assert('root username is rejected', $root['exit_code'] === 1, $root['output']);

$rootHost = equity_rc_run([
    '--env-file='.equity_rc_fixture('root-host.env.integration'),
    '--project-root='.$cleanRoot,
]);
equity_rc_assert('root@host username is rejected', $rootHost['exit_code'] === 1, $rootHost['output']);

$password = equity_rc_run([
    '--env-file='.equity_rc_fixture('with-password.env.integration'),
    '--project-root='.$cleanRoot,
]);
equity_rc_assert('approved file with withheld password still passes', $password['exit_code'] === 0, $password['output']);
equity_rc_assert(
    'secret values never appear in output',
    $password['exit_code'] === 0 && equity_rc_has_secret($password['output']) === false && str_contains($password['output'], '(withheld)'),
    $password['output'],
);

$malformed = equity_rc_run([
    '--env-file='.equity_rc_fixture('malformed.env.integration'),
    '--project-root='.$cleanRoot,
]);
equity_rc_assert('malformed lines fail closed', $malformed['exit_code'] === 1, $malformed['output']);

$cliFail = equity_rc_spawn($scriptPath, [
    '--env-file='.equity_rc_fixture('protected-equity.env.integration'),
    '--project-root='.$cleanRoot,
]);
equity_rc_assert(
    'real CLI entrypoint returns nonzero for unsafe input',
    $cliFail['exit_code'] === 1 && str_contains($cliFail['output'], 'FAIL'),
    $cliFail['output'],
);
equity_rc_assert(
    'CLI ignores process DB_* environment variables',
    $cliFail['exit_code'] === 1 && equity_rc_has_secret($cliFail['output']) === false,
    $cliFail['output'],
);

$cliPass = equity_rc_spawn($scriptPath, [
    '--env-file='.$cleanApproved,
    '--project-root='.$cleanRoot,
]);
equity_rc_assert(
    'real CLI entrypoint accepts the approved fixture',
    $cliPass['exit_code'] === 0 && str_contains($cliPass['output'], 'PASS'),
    $cliPass['output'],
);

$noArgs = equity_rc_run([]);
equity_rc_assert('missing arguments fail closed', $noArgs['exit_code'] === 1, $noArgs['output']);

$unknown = equity_rc_run([
    '--env-file='.$cleanApproved,
    '--project-root='.$cleanRoot,
    '--password=s3cret-value',
]);
equity_rc_assert('unexpected arguments fail closed', $unknown['exit_code'] === 1, $unknown['output']);
equity_rc_assert('unexpected argument secrets are not printed', equity_rc_has_secret($unknown['output']) === false, $unknown['output']);

echo "\n{$passed} passed, {$failed} failed\n";

if ($failed > 0) {
    fwrite(STDERR, "\nFailed cases:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

exit(0);
