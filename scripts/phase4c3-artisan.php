<?php

/**
 * Phase 4C.3 isolated Artisan entry point.
 *
 * Redirects Laravel Dotenv loading to the runner-owned disposable environment file
 * before Kernel bootstrap. Never uses stock artisan against the repository `.env`.
 */

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Tests\Integration\Phase4C3\Phase4C3Isolation;

define('LARAVEL_START', microtime(true));

$repoRoot = dirname(__DIR__);
require $repoRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';

$derivedRepo = realpath($repoRoot);
if ($derivedRepo === false) {
    fwrite(STDERR, "Phase 4C.3 artisan: repository root could not be resolved.\n");
    exit(2);
}
$derivedRepo = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $derivedRepo);

try {
    Phase4C3Isolation::bootFromEnvironment($derivedRepo);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(2);
}

/** @var Application $app */
$app = require $repoRoot.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php';

try {
    Phase4C3Isolation::redirectEnvironmentAwayFromProtectedDotEnv($app);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(2);
}

// Fail closed: refuse if redirect still points at a protected basename.
$envFile = basename(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $app->environmentFilePath()));
if ($envFile === '.env' || strcasecmp($envFile, '.env') === 0) {
    fwrite(STDERR, "Phase 4C.3 artisan: refused to boot with protected .env basename.\n");
    exit(2);
}

$status = $app->handleCommand(new ArgvInput);

exit($status);
