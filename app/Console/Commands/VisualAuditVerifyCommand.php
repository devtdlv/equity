<?php

namespace App\Console\Commands;

use App\Support\VisualAuditEnvironment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Isolation gate for visual-audit. Does not migrate, seed, or print secrets.
 */
class VisualAuditVerifyCommand extends Command
{
    protected $signature = 'equity:visual-audit-verify';

    protected $description = 'Verify visual-audit environment isolation before migrate/seed';

    public function handle(): int
    {
        $this->info('Verifying visual-audit isolation...');

        $checks = VisualAuditEnvironment::verificationChecks();
        $rows = [];
        $failures = [];

        foreach ($checks as $check) {
            $rows[] = [$check['label'], $check['passed'] ? 'PASS' : 'FAIL'];
            if (! $check['passed']) {
                $failures[] = is_string($check['detail']) && $check['detail'] !== ''
                    ? $check['detail']
                    : $check['label'].' failed.';
            }
        }

        $this->table(['Check', 'Result'], $rows);

        $databaseConfigured = (string) config('database.connections.sqlite.database');
        $databaseDisplay = VisualAuditEnvironment::normalizedDatabasePath($databaseConfigured);
        $this->line('Effective SQLite target: '.$this->redactHome($databaseDisplay));
        $this->line('Forbidden real DB path: '.$this->redactHome(VisualAuditEnvironment::forbiddenDatabasePath()));

        if ($failures !== []) {
            $this->newLine();
            $this->error('Isolation verification FAILED:');
            foreach ($failures as $failure) {
                $this->line(' - '.$failure);
            }

            return self::FAILURE;
        }

        // Connection probe only after every table check has already passed.
        if ($databaseDisplay !== ':memory:' && is_file($databaseDisplay)) {
            try {
                $size = filesize($databaseDisplay);
                $this->line('Disposable DB size bytes: '.($size === false ? 'unknown' : (string) $size));
                DB::connection()->getPdo();
                $this->line('SQLite PDO connection: PASS');
            } catch (Throwable $exception) {
                $this->error('SQLite PDO connection failed: '.$exception->getMessage());

                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->info('All isolation checks passed. Migrations/seeders are still blocked pending operator approval.');

        return self::SUCCESS;
    }

    private function redactHome(string $path): string
    {
        $home = getenv('USERPROFILE') ?: getenv('HOME') ?: '';
        if (is_string($home) && $home !== '' && str_starts_with($path, str_replace('\\', '/', $home))) {
            return '~'.substr($path, strlen(str_replace('\\', '/', $home)));
        }

        $local = getenv('LOCALAPPDATA');
        if (is_string($local) && $local !== '') {
            $normalizedLocal = str_replace('\\', '/', $local);
            if (str_starts_with($path, $normalizedLocal)) {
                return '%LOCALAPPDATA%'.substr($path, strlen($normalizedLocal));
            }
        }

        return $path;
    }
}
