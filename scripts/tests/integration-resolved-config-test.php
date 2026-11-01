<?php

/**
 * Isolated offline tests for scripts/integration-resolved-config.php.
 *
 * Uses synthetic fixtures only. Does not bootstrap Laravel, read the protected
 * .env, or connect to a database. Does not mutate the parent process environment.
 */

declare(strict_types=1);

$scriptPath = dirname(__DIR__).DIRECTORY_SEPARATOR.'integration-resolved-config.php';
$fixtureDir = __DIR__.DIRECTORY_SEPARATOR.'fixtures';
require $scriptPath;

$passed = 0;
$failed = 0;
$failures = [];
$parentHadDatabase = getenv('DB_DATABASE') !== false;
$parentHadUrl = getenv('DB_URL') !== false;
$parentHadCache = getenv('APP_CONFIG_CACHE') !== false;

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
 * Sanitized child environment. Does not copy parent DB_* / APP_* selectors.
 *
 * @return array<string, string>
 */
function equity_rc_sanitized_env(): array
{
    return [
        'PATH' => (string) (getenv('PATH') ?: ''),
        'PATHEXT' => (string) (getenv('PATHEXT') ?: '.COM;.EXE;.BAT;.CMD'),
        'SystemRoot' => (string) (getenv('SystemRoot') ?: 'C:\\Windows'),
        'SystemDrive' => (string) (getenv('SystemDrive') ?: 'C:'),
        'COMSPEC' => (string) (getenv('COMSPEC') ?: ''),
        'TEMP' => (string) (getenv('TEMP') ?: sys_get_temp_dir()),
        'TMP' => (string) (getenv('TMP') ?: sys_get_temp_dir()),
    ];
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
    $env = array_merge(equity_rc_sanitized_env(), $extraEnv);

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

/**
 * Child process that sets empty or poisoned keys via putenv so empty assignments
 * are visible to PHP even when the OS environment block drops blank values.
 *
 * @param array<string, string> $putenvAssignments
 * @param list<string> $args
 * @return array{exit_code: int, output: string}
 */
function equity_rc_spawn_putenv(array $putenvAssignments, array $args): array
{
    global $scriptPath;

    $runner = sys_get_temp_dir().DIRECTORY_SEPARATOR.'equity-it-rc-runner-'.bin2hex(random_bytes(6)).'.php';
    $lines = [
        '<?php',
        'declare(strict_types=1);',
    ];
    foreach ($putenvAssignments as $key => $value) {
        $lines[] = 'putenv('.var_export($key.'='.$value, true).');';
        $lines[] = '$_ENV['.var_export($key, true).'] = '.var_export($value, true).';';
        $lines[] = '$_SERVER['.var_export($key, true).'] = '.var_export($value, true).';';
    }
    $lines[] = '$argv = '.var_export(array_merge(['integration-resolved-config.php'], $args), true).';';
    $lines[] = '$_SERVER[\'SCRIPT_FILENAME\'] = '.var_export($scriptPath, true).';';
    $lines[] = 'require '.var_export($scriptPath, true).';';
    file_put_contents($runner, implode("\n", $lines)."\n");

    $result = equity_rc_spawn($runner, []);
    @unlink($runner);

    return $result;
}

function equity_rc_run(array $flags): array
{
    return equity_resolved_config(array_merge(['integration-resolved-config.php'], $flags));
}

function equity_rc_inspect(string $envFile, string $projectRoot): array
{
    $inspected = equity_resolved_inspect_env_file($envFile, $projectRoot);
    $lines = equity_resolved_format_report(
        $inspected['env_file'],
        $inspected['project_root'],
        $inspected['values'],
        $inspected['secret_keys'],
        $inspected['errors'],
    );

    return [
        'exit_code' => $inspected['errors'] === [] ? 0 : 1,
        'output' => implode("\n", $lines)."\n",
        'values' => $inspected['values'],
        'errors' => $inspected['errors'],
    ];
}

function equity_rc_fixture(string $name): string
{
    global $fixtureDir;

    return $fixtureDir.DIRECTORY_SEPARATOR.$name;
}

/**
 * @param array<string, string|null> $overrides
 */
function equity_rc_write_env(string $directory, string $filename, array $overrides = []): string
{
    $values = [
        'APP_ENV' => 'integration',
        'DB_CONNECTION' => 'mariadb',
        'DB_DATABASE' => EQUITY_INTEGRATION_APPROVED_DATABASE,
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '3306',
        'DB_USERNAME' => EQUITY_INTEGRATION_APPROVED_USERNAME,
    ];
    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($values[$key]);
        } else {
            $values[$key] = $value;
        }
    }

    $path = $directory.DIRECTORY_SEPARATOR.$filename;
    $lines = ['# Synthetic fixture. Not a real credential file.'];
    foreach ($values as $key => $value) {
        $lines[] = $key.'='.$value;
    }
    file_put_contents($path, implode("\n", $lines)."\n");

    return $path;
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
    if (preg_match('/new PDO|\\bPDO\\b|mysqli_|SQLite3|Illuminate\\\\|artisan|mysql_connect|pg_connect|PDO::/i', $source) === 1) {
        return false;
    }

    if (preg_match('/\$envValues\[[^\]]+\]\s*=\s*getenv\s*\(/', $source) === 1) {
        return false;
    }

    if (preg_match('/getenv\s*\([^)]+\)\s*\?\?/', $source) === 1) {
        return false;
    }

    return true;
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

$source = (string) file_get_contents($scriptPath);

equity_rc_assert('no Laravel bootstrap is required', $bootstrapped === false);
equity_rc_assert(
    'resolved-config source has no database APIs or env fallbacks',
    equity_rc_source_is_offline($source),
);
equity_rc_assert(
    'process environment is inspected for presence only',
    str_contains($source, 'function equity_resolved_process_key_present')
        && str_contains($source, 'getenv($key) !== false'),
);
equity_rc_assert(
    'canonical repository cache path is always inspected',
    str_contains($source, 'equity_resolved_canonical_repository_root')
        && str_contains($source, 'equity_resolved_config_cache_path($canonical)'),
);
equity_rc_assert(
    'CLI has no caller-identity, backtrace, or test-mode bypass',
    ! str_contains($source, 'debug_backtrace')
        && ! str_contains($source, 'equity_resolved_invoked_from_official_offline_test')
        && ! str_contains($source, '--test-mode')
        && ! str_contains($source, 'allow-synthetic'),
);

$canonicalRoot = equity_resolved_canonical_repository_root();
equity_rc_assert(
    'canonical repository root resolves from the script location',
    is_string($canonicalRoot) && $canonicalRoot !== '',
);

$boundCanonical = equity_resolved_bind_project_root((string) $canonicalRoot);
equity_rc_assert(
    'binding the canonical repository root succeeds',
    $boundCanonical['errors'] === [] && is_string($boundCanonical['root']),
    implode('; ', $boundCanonical['errors']),
);

$inheritedClean = equity_resolved_inherited_environment_errors_from_present_keys([]);
equity_rc_assert('explicit empty inherited-key list has no errors', $inheritedClean === []);

$inheritedPoison = equity_resolved_inherited_environment_errors_from_present_keys(['DB_DATABASE', 'DB_URL', 'APP_CONFIG_CACHE']);
equity_rc_assert(
    'explicit inherited-key list fails without printing values',
    $inheritedPoison !== []
        && str_contains($inheritedPoison[0], 'DB_DATABASE')
        && str_contains($inheritedPoison[0], 'DB_URL')
        && str_contains($inheritedPoison[0], 'APP_CONFIG_CACHE')
        && ! str_contains($inheritedPoison[0], 'equity')
        && ! str_contains($inheritedPoison[0], '='),
    $inheritedPoison[0] ?? '',
);

$cleanRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'equity-it-clean-'.bin2hex(random_bytes(6));
mkdir($cleanRoot, 0777, true);
$cleanApproved = $cleanRoot.DIRECTORY_SEPARATOR.'approved.env.integration';
copy(equity_rc_fixture('approved.env.integration'), $cleanApproved);

$approved = equity_rc_inspect($cleanApproved, $cleanRoot);
equity_rc_assert('approved fixture passes', $approved['exit_code'] === 0, $approved['output']);
equity_rc_assert(
    'exact 127.0.0.1 host in approved fixture is accepted',
    $approved['exit_code'] === 0 && str_contains($approved['output'], 'DB_HOST=127.0.0.1'),
    $approved['output'],
);

$boundSynthetic = equity_resolved_bind_project_root($cleanRoot);
equity_rc_assert(
    'synthetic project root is rejected by the bind helper',
    $boundSynthetic['root'] === null && str_contains(implode(' ', $boundSynthetic['errors']), 'Unbound --project-root'),
    implode('; ', $boundSynthetic['errors']),
);

$tempEnv = sys_get_temp_dir().DIRECTORY_SEPARATOR.'equity-it-'.bin2hex(random_bytes(6));
mkdir($tempEnv, 0777, true);
$namedDotEnv = $tempEnv.DIRECTORY_SEPARATOR.'.env';
copy(equity_rc_fixture('approved.env.integration'), $namedDotEnv);
$dotEnv = equity_rc_inspect($namedDotEnv, $tempEnv);
equity_rc_assert(
    'file basename .env is rejected without treating it as approved',
    $dotEnv['exit_code'] === 1 && str_contains($dotEnv['output'], 'Protected .env'),
    $dotEnv['output'],
);

$equity = equity_rc_inspect(equity_rc_fixture('protected-equity.env.integration'), $cleanRoot);
equity_rc_assert(
    'protected database equity is rejected',
    $equity['exit_code'] === 1 && str_contains(strtolower($equity['output']), 'protected'),
    $equity['output'],
);

$wrong = equity_rc_inspect(equity_rc_fixture('wrong-name.env.integration'), $cleanRoot);
equity_rc_assert(
    'wrong disposable name is rejected',
    $wrong['exit_code'] === 1 && str_contains($wrong['output'], EQUITY_INTEGRATION_APPROVED_DATABASE),
    $wrong['output'],
);

$missing = equity_rc_inspect(equity_rc_fixture('missing-host.env.integration'), $cleanRoot);
equity_rc_assert(
    'missing required values are rejected',
    $missing['exit_code'] === 1 && str_contains($missing['output'], 'DB_HOST'),
    $missing['output'],
);

$localhostHost = equity_rc_write_env($cleanRoot, 'localhost-host.env.integration', ['DB_HOST' => 'localhost']);
$localhost = equity_rc_inspect($localhostHost, $cleanRoot);
equity_rc_assert(
    'localhost host in env file is rejected',
    $localhost['exit_code'] === 1 && str_contains(strtolower($localhost['output']), 'localhost'),
    $localhost['output'],
);

$wildcardHost = equity_rc_write_env($cleanRoot, 'wildcard-host.env.integration', ['DB_HOST' => '0.0.0.0']);
$wildcard = equity_rc_inspect($wildcardHost, $cleanRoot);
equity_rc_assert(
    'wildcard host in env file is rejected',
    $wildcard['exit_code'] === 1,
    $wildcard['output'],
);

$urlHost = equity_rc_write_env($cleanRoot, 'url-host.env.integration', ['DB_HOST' => 'mysql://127.0.0.1']);
$urlLikeHost = equity_rc_inspect($urlHost, $cleanRoot);
equity_rc_assert(
    'URL-like host in env file is rejected',
    $urlLikeHost['exit_code'] === 1 && str_contains($urlLikeHost['output'], 'URL-like'),
    $urlLikeHost['output'],
);

$url = equity_rc_inspect(equity_rc_fixture('with-url.env.integration'), $cleanRoot);
equity_rc_assert('DB_URL is rejected', $url['exit_code'] === 1 && str_contains($url['output'], 'DB_URL'), $url['output']);
equity_rc_assert('DB_URL value is not printed', equity_rc_has_secret($url['output']) === false, $url['output']);

$emptyUrl = equity_rc_inspect(equity_rc_fixture('empty-url.env.integration'), $cleanRoot);
equity_rc_assert('empty DB_URL assignment is rejected', $emptyUrl['exit_code'] === 1 && str_contains($emptyUrl['output'], 'DB_URL'), $emptyUrl['output']);

$socket = equity_rc_inspect(equity_rc_fixture('with-socket.env.integration'), $cleanRoot);
equity_rc_assert('DB_SOCKET is rejected', $socket['exit_code'] === 1 && str_contains($socket['output'], 'DB_SOCKET'), $socket['output']);
equity_rc_assert('socket path is not printed', equity_rc_has_secret($socket['output']) === false, $socket['output']);

$cacheRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'equity-it-cache-'.bin2hex(random_bytes(6));
$cacheDir = $cacheRoot.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache';
mkdir($cacheDir, 0777, true);
file_put_contents($cacheDir.DIRECTORY_SEPARATOR.'config.php', "<?php\nreturn [];\n");
$approvedCopy = $cacheRoot.DIRECTORY_SEPARATOR.'approved.env.integration';
copy(equity_rc_fixture('approved.env.integration'), $approvedCopy);
$cached = equity_rc_inspect($approvedCopy, $cacheRoot);
$cacheHelper = equity_resolved_check_cache_state($cacheRoot, []);
equity_rc_assert(
    'cached Laravel configuration is rejected',
    $cached['exit_code'] === 1 && str_contains($cached['output'], 'config cache'),
    $cached['output'],
);
equity_rc_assert(
    'synthetic-root cache helper still inspects the canonical repository cache path',
    $cacheHelper !== []
        && str_contains($source, 'equity_resolved_config_cache_path($canonical)')
        && str_contains(implode(' ', $cacheHelper), 'config cache'),
    implode('; ', $cacheHelper),
);

$appCache = equity_rc_inspect(equity_rc_fixture('app-config-cache.env.integration'), $cleanRoot);
equity_rc_assert(
    'APP_CONFIG_CACHE override is rejected',
    $appCache['exit_code'] === 1 && str_contains($appCache['output'], 'APP_CONFIG_CACHE'),
    $appCache['output'],
);

$duplicate = equity_rc_inspect(equity_rc_fixture('duplicate-keys.env.integration'), $cleanRoot);
equity_rc_assert(
    'duplicate keys are rejected',
    $duplicate['exit_code'] === 1 && str_contains($duplicate['output'], 'Duplicate'),
    $duplicate['output'],
);

$interpolation = equity_rc_inspect(equity_rc_fixture('interpolation.env.integration'), $cleanRoot);
equity_rc_assert(
    'interpolation is rejected',
    $interpolation['exit_code'] === 1 && str_contains($interpolation['output'], 'interpolation'),
    $interpolation['output'],
);

$commandSub = $tempEnv.DIRECTORY_SEPARATOR.'command.env.integration';
file_put_contents($commandSub, "APP_ENV=integration\nDB_HOST=`hostname`\n");
$commandResult = equity_rc_inspect($commandSub, $tempEnv);
equity_rc_assert(
    'command substitution is rejected',
    $commandResult['exit_code'] === 1 && str_contains($commandResult['output'], 'command substitution'),
    $commandResult['output'],
);

$root = equity_rc_inspect(equity_rc_fixture('root-user.env.integration'), $cleanRoot);
equity_rc_assert('root username is rejected', $root['exit_code'] === 1, $root['output']);

$rootHost = equity_rc_inspect(equity_rc_fixture('root-host.env.integration'), $cleanRoot);
equity_rc_assert('root@host username is rejected', $rootHost['exit_code'] === 1, $rootHost['output']);

$password = equity_rc_inspect(equity_rc_fixture('with-password.env.integration'), $cleanRoot);
equity_rc_assert('approved file with withheld password still passes', $password['exit_code'] === 0, $password['output']);
equity_rc_assert(
    'secret values never appear in output',
    $password['exit_code'] === 0 && equity_rc_has_secret($password['output']) === false && str_contains($password['output'], '(withheld)'),
    $password['output'],
);

$malformed = equity_rc_inspect(equity_rc_fixture('malformed.env.integration'), $cleanRoot);
equity_rc_assert('malformed lines fail closed', $malformed['exit_code'] === 1, $malformed['output']);

$cliFail = equity_rc_spawn($scriptPath, [
    '--env-file='.equity_rc_fixture('protected-equity.env.integration'),
]);
equity_rc_assert(
    'real CLI entrypoint returns nonzero for unsafe input',
    $cliFail['exit_code'] === 1 && str_contains($cliFail['output'], 'FAIL'),
    $cliFail['output'],
);
equity_rc_assert(
    'CLI unsafe output does not print secrets',
    equity_rc_has_secret($cliFail['output']) === false,
    $cliFail['output'],
);

$cliPass = equity_rc_spawn($scriptPath, [
    '--env-file='.$cleanApproved,
]);
equity_rc_assert(
    'clean subprocess environment passes',
    $cliPass['exit_code'] === 0 && str_contains($cliPass['output'], 'PASS'),
    $cliPass['output'],
);
equity_rc_assert(
    'clean subprocess does not print secrets',
    equity_rc_has_secret($cliPass['output']) === false,
    $cliPass['output'],
);

$cliUnbound = equity_rc_spawn($scriptPath, [
    '--env-file='.$cleanApproved,
    '--project-root='.$cleanRoot,
]);
equity_rc_assert(
    'unbound project root fails in normal CLI mode',
    $cliUnbound['exit_code'] === 1 && str_contains($cliUnbound['output'], 'Unbound --project-root'),
    $cliUnbound['output'],
);

$inProcessUnbound = equity_rc_run([
    '--env-file='.$cleanApproved,
    '--project-root='.$cleanRoot,
]);
equity_rc_assert(
    'in-process CLI entry also rejects unbound project root with no caller bypass',
    $inProcessUnbound['exit_code'] === 1 && str_contains($inProcessUnbound['output'], 'Unbound --project-root'),
    $inProcessUnbound['output'],
);

$cliPoisonDatabase = equity_rc_spawn($scriptPath, [
    '--env-file='.$cleanApproved,
], [
    'DB_DATABASE' => 'equity',
    'DB_PASSWORD' => 's3cret-value',
]);
equity_rc_assert(
    'inherited DB_DATABASE=equity fails in layer 2',
    $cliPoisonDatabase['exit_code'] === 1
        && str_contains($cliPoisonDatabase['output'], 'Inherited process environment')
        && str_contains($cliPoisonDatabase['output'], 'DB_DATABASE'),
    $cliPoisonDatabase['output'],
);
equity_rc_assert(
    'inherited DB_DATABASE value is not printed',
    equity_rc_has_secret($cliPoisonDatabase['output']) === false
        && ! str_contains($cliPoisonDatabase['output'], 'DB_DATABASE=equity'),
    $cliPoisonDatabase['output'],
);

$cliPoisonUrl = equity_rc_spawn($scriptPath, [
    '--env-file='.$cleanApproved,
], [
    'DB_URL' => 'mysql://example.invalid/equity',
]);
equity_rc_assert(
    'inherited DB_URL fails in layer 2',
    $cliPoisonUrl['exit_code'] === 1
        && str_contains($cliPoisonUrl['output'], 'Inherited process environment')
        && str_contains($cliPoisonUrl['output'], 'DB_URL'),
    $cliPoisonUrl['output'],
);
equity_rc_assert(
    'inherited DB_URL value is not printed',
    equity_rc_has_secret($cliPoisonUrl['output']) === false,
    $cliPoisonUrl['output'],
);

$cliPoisonCache = equity_rc_spawn($scriptPath, [
    '--env-file='.$cleanApproved,
], [
    'APP_CONFIG_CACHE' => 'C:\\not-the-repo\\bootstrap\\cache\\config.php',
]);
equity_rc_assert(
    'inherited APP_CONFIG_CACHE fails in layer 2',
    $cliPoisonCache['exit_code'] === 1
        && str_contains($cliPoisonCache['output'], 'Inherited process environment')
        && str_contains($cliPoisonCache['output'], 'APP_CONFIG_CACHE'),
    $cliPoisonCache['output'],
);
equity_rc_assert(
    'inherited APP_CONFIG_CACHE value is not printed',
    ! str_contains($cliPoisonCache['output'], 'not-the-repo'),
    $cliPoisonCache['output'],
);

$cliEmptyInherited = equity_rc_spawn_putenv([
    'DB_DATABASE' => '',
    'DB_URL' => '',
    'APP_CONFIG_CACHE' => '',
    'APP_ENV' => '',
], [
    '--env-file='.$cleanApproved,
]);
equity_rc_assert(
    'empty inherited dangerous variables fail',
    $cliEmptyInherited['exit_code'] === 1
        && str_contains($cliEmptyInherited['output'], 'Inherited process environment')
        && str_contains($cliEmptyInherited['output'], 'DB_DATABASE')
        && str_contains($cliEmptyInherited['output'], 'DB_URL')
        && str_contains($cliEmptyInherited['output'], 'APP_CONFIG_CACHE'),
    $cliEmptyInherited['output'],
);

$testMode = equity_rc_run([
    '--env-file='.$cleanApproved,
    '--project-root='.$cleanRoot,
    '--test-mode=1',
]);
equity_rc_assert(
    'caller-provided test-mode flag cannot enable synthetic roots in ordinary use',
    $testMode['exit_code'] === 1 && str_contains($testMode['output'], 'Unknown flag'),
    $testMode['output'],
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

equity_rc_assert(
    'parent process environment was not mutated',
    (getenv('DB_DATABASE') !== false) === $parentHadDatabase
        && (getenv('DB_URL') !== false) === $parentHadUrl
        && (getenv('APP_CONFIG_CACHE') !== false) === $parentHadCache,
);

echo "\n{$passed} passed, {$failed} failed\n";

if ($failed > 0) {
    fwrite(STDERR, "\nFailed cases:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

exit(0);
