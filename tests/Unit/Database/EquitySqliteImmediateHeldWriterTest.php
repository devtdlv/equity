<?php

namespace Tests\Unit\Database;

use App\Database\SQLite\EquitySQLiteConnection;
use App\Database\SQLite\ImmediateAwareSqlitePdo;
use App\Support\FinancialDatabaseTransaction;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Disposable file-SQLite tests (outside the repo). Does not extend Tests\TestCase
 * so :memory: guards do not apply. Never touches database/database.sqlite.
 */
final class EquitySqliteImmediateHeldWriterTest extends TestCase
{
    private string $path;

    private string $forbidden;

    protected function setUp(): void
    {
        parent::setUp();

        $token = bin2hex(random_bytes(8));
        $this->path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'equity-4c1-held-'.$token.'.sqlite';
        if (file_exists($this->path)) {
            $this->fail('Refusing to reuse an existing disposable path.');
        }
        touch($this->path);

        $repoRoot = dirname(__DIR__, 3);
        $this->forbidden = $repoRoot.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite';
        $this->assertNotSame(
            realpath($this->path) ?: $this->path,
            realpath($this->forbidden) ?: $this->forbidden,
        );

        $app = require $repoRoot.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php';
        $app->make(Kernel::class)->bootstrap();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->path,
            'database.connections.sqlite.busy_timeout' => 100,
            'database.connections.sqlite.foreign_key_constraints' => true,
            'database.connections.sqlite.journal_mode' => 'wal',
            'equity.sqlite.financial_immediate' => true,
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::statement('CREATE TABLE IF NOT EXISTS held_probe (id INTEGER PRIMARY KEY, v TEXT)');
    }

    protected function tearDown(): void
    {
        try {
            DB::disconnect('sqlite');
        } catch (\Throwable) {
        }

        // Laravel bootstrap registers handlers; remove them so PHPUnit does not mark the test risky.
        restore_exception_handler();
        restore_error_handler();

        foreach ([$this->path, $this->path.'-wal', $this->path.'-shm', $this->path.'-journal'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        config(['equity.sqlite.financial_immediate' => false]);
        parent::tearDown();
    }

    #[Test]
    public function failed_begin_immediate_under_held_writer_translates_to_query_exception_with_codes(): void
    {
        $this->assertInstanceOf(EquitySQLiteConnection::class, DB::connection());
        $this->assertInstanceOf(ImmediateAwareSqlitePdo::class, DB::connection()->getPdo());

        $holder = new ImmediateAwareSqlitePdo('sqlite:'.$this->path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $holder->exec('PRAGMA busy_timeout = 100');
        $holder->setPendingBeginMode(ImmediateAwareSqlitePdo::MODE_IMMEDIATE);
        $holder->beginTransaction();
        $holder->exec("INSERT INTO held_probe (v) VALUES ('held')");

        try {
            FinancialDatabaseTransaction::run(function (): void {
                DB::table('held_probe')->insert(['v' => 'contender']);
            });
            $this->fail('Expected begin/lock failure while holder owns the write lock.');
        } catch (QueryException $e) {
            $this->assertSame(5, (int) ($e->errorInfo[1] ?? 0), 'Preserved SQLITE_BUSY driver code: '.json_encode($e->errorInfo));
            $this->assertSame('BEGIN IMMEDIATE TRANSACTION', $e->getSql());
            $this->assertInstanceOf(PDOException::class, $e->getPrevious());
            $previous = $e->getPrevious();
            $this->assertSame(5, (int) ($previous->errorInfo[1] ?? 0));
        } finally {
            $holder->rollBack();
            $holder = null;
        }

        /** @var EquitySQLiteConnection $connection */
        $connection = DB::connection();
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertNull($connection->nextOutermostMode());
        $this->assertNull($connection->outermostBeginMode());
        // BEGIN busy translation must consume the failure latch (no stale marker).
        $this->assertFalse($connection->consumeImmediateBeginFailurePending());

        $integrity = DB::selectOne('PRAGMA integrity_check');
        $value = is_object($integrity) ? (string) array_values((array) $integrity)[0] : '';
        $this->assertSame('ok', strtolower($value));
        $this->assertSame(0, (int) DB::table('held_probe')->count());
    }
}
