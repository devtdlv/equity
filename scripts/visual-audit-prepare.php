<?php

/**
 * Standalone visual-audit env preparation.
 *
 * Does not bootstrap Laravel and does not open, read, or copy the real `.env`.
 * Creates `.env.visual-audit` and an empty disposable SQLite file outside the repo
 * under the dedicated EquityVisualAudit data directory.
 *
 * Existing `.env.visual-audit` and existing disposable SQLite files are preserved.
 * Unsafe regeneration (`--force`) is not supported.
 */

declare(strict_types=1);

use App\Support\VisualAuditEnvironment;

require dirname(__DIR__).DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';

if (in_array('--force', $argv, true)) {
    fwrite(STDERR, "Refusing --force: regenerating ".VisualAuditEnvironment::ENV_FILE_NAME
        ." would replace the dedicated APP_KEY and can retarget the disposable database.\n");
    fwrite(STDERR, "Existing visual-audit env/database files are preserved by design.\n");
    exit(1);
}

$databaseOption = null;
foreach ($argv as $index => $arg) {
    if ($arg === '--database' && isset($argv[$index + 1])) {
        $databaseOption = $argv[$index + 1];
    }
    if (str_starts_with($arg, '--database=')) {
        $databaseOption = substr($arg, strlen('--database='));
    }
}

try {
    $result = VisualAuditEnvironment::prepareDisposableEnvironment(
        is_string($databaseOption) ? $databaseOption : null,
    );
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}

fwrite(STDOUT, 'Wrote '.VisualAuditEnvironment::ENV_FILE_NAME." (gitignored).\n");
fwrite(STDOUT, 'Disposable SQLite: '.$result['database_path']."\n");
fwrite(STDOUT, $result['created_database']
    ? "Created empty disposable SQLite file.\n"
    : "Preserved existing disposable SQLite file.\n");
fwrite(STDOUT, "Next: php artisan equity:visual-audit-verify --env=visual-audit\n");
fwrite(STDOUT, "Do not migrate/seed until verify passes and an operator approves execution.\n");
exit(0);
