<?php

/**
 * Static proof that Phase 4C.3 isolation entry points cannot use stock .env/DB bootstrap.
 * Does not open the repository .env or connect to any database.
 */

declare(strict_types=1);

$repo = dirname(__DIR__);
$failures = [];

$isolationXml = file_get_contents($repo.DIRECTORY_SEPARATOR.'phpunit.phase4c3-environment-isolation.xml');
if ($isolationXml === false || ! str_contains($isolationXml, 'bootstrap="vendor/autoload.php"')) {
    $failures[] = 'isolation phpunit must bootstrap vendor/autoload.php only';
}
if (is_string($isolationXml) && str_contains($isolationXml, 'Phase4C3/bootstrap')) {
    $failures[] = 'isolation phpunit must not use Phase4C3 Laravel bootstrap';
}

$artisan = file_get_contents($repo.DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'phase4c3-artisan.php');
if ($artisan === false
    || ! str_contains($artisan, 'redirectEnvironmentAwayFromProtectedDotEnv')
    || ! str_contains($artisan, 'bootFromEnvironment')) {
    $failures[] = 'phase4c3-artisan.php must boot isolation and redirect before handleCommand';
}

$runner = file_get_contents($repo.DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'phase4c3-sqlite-concurrency-runner.php');
if ($runner === false || ! str_contains($runner, 'phase4c3-artisan.php')) {
    $failures[] = 'runner must invoke phase4c3-artisan.php';
}
if (is_string($runner) && preg_match('/\$artisan\s*=\s*\$repoReal\.DIRECTORY_SEPARATOR\.\'artisan\'/', $runner)) {
    $failures[] = 'runner must not bind stock artisan path';
}
if (is_string($runner) && ! str_contains($runner, 'EQUITY_PHASE4C3_ENV_FILE')) {
    $failures[] = 'runner must export EQUITY_PHASE4C3_ENV_FILE';
}

$testCase = file_get_contents($repo.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Integration'.DIRECTORY_SEPARATOR.'Phase4C3'.DIRECTORY_SEPARATOR.'Phase4C3IntegrationTestCase.php');
if ($testCase === false || ! str_contains($testCase, 'redirectEnvironmentAwayFromProtectedDotEnv')) {
    $failures[] = 'Phase4C3IntegrationTestCase must redirect before Kernel bootstrap';
}

$worker = file_get_contents($repo.DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'phase4c3-worker.php');
if ($worker === false || ! str_contains($worker, 'redirectEnvironmentAwayFromProtectedDotEnv')) {
    $failures[] = 'phase4c3-worker.php must redirect before Kernel bootstrap';
}

$equity = file_get_contents($repo.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'equity.php');
if ($equity === false || ! str_contains($equity, "'financial_immediate' => false")) {
    $failures[] = 'equity.sqlite.financial_immediate must remain false by default';
}

if ($failures !== []) {
    fwrite(STDERR, "FAIL isolation entry-point proof:\n- ".implode("\n- ", $failures)."\n");
    exit(2);
}

fwrite(STDOUT, "PASS isolation entry-point proof\n");
fwrite(STDOUT, "- phpunit.phase4c3-environment-isolation.xml: Composer autoload only\n");
fwrite(STDOUT, "- phase4c3-artisan.php: isolation boot + env redirect before handleCommand\n");
fwrite(STDOUT, "- runner: isolated artisan + EQUITY_PHASE4C3_ENV_FILE\n");
fwrite(STDOUT, "- TestCase + worker: redirect before Kernel::bootstrap()\n");
fwrite(STDOUT, "- financial_immediate default remains false\n");
exit(0);
