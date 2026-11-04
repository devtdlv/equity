<?php

declare(strict_types=1);

use Tests\Integration\Phase4C3\Phase4C3Isolation;

$projectRoot = dirname(__DIR__, 3);
$autoload = $projectRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';

if (! is_file($autoload)) {
    fwrite(STDERR, "Phase 4C.3 bootstrap failed: Composer autoload is missing.\n");
    exit(2);
}

require $autoload;

$derivedRepoRoot = realpath($projectRoot);
if ($derivedRepoRoot === false) {
    fwrite(STDERR, "Phase 4C.3 bootstrap failed: project root could not be resolved from bootstrap.php.\n");
    exit(2);
}

try {
    Phase4C3Isolation::bootFromEnvironment($derivedRepoRoot);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(2);
}
