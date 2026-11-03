<?php

namespace Tests\Unit\Database;

use App\Database\SQLite\ImmediateAwareSqlitePdo;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ImmediateAwareSqlitePdoTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'equity-4c1-pdo-'.bin2hex(random_bytes(8)).'.sqlite';
        touch($this->path);
    }

    protected function tearDown(): void
    {
        // Ensure PDO handles from the test body are released before Windows unlink.
        gc_collect_cycles();
        foreach ([$this->path, $this->path.'-wal', $this->path.'-shm', $this->path.'-journal'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        parent::tearDown();
    }

    private function open(): ImmediateAwareSqlitePdo
    {
        $pdo = new ImmediateAwareSqlitePdo('sqlite:'.$this->path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 100');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('CREATE TABLE IF NOT EXISTS probe (id INTEGER PRIMARY KEY, v TEXT)');

        return $pdo;
    }

    #[Test]
    public function deferred_begin_commit_and_rollback_track_in_transaction(): void
    {
        $pdo = $this->open();
        $this->assertFalse($pdo->inTransaction());
        $pdo->beginTransaction();
        $this->assertTrue($pdo->inTransaction());
        $pdo->exec("INSERT INTO probe (v) VALUES ('a')");
        $pdo->rollBack();
        $this->assertFalse($pdo->inTransaction());
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM probe')->fetchColumn());

        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO probe (v) VALUES ('b')");
        $pdo->commit();
        $this->assertFalse($pdo->inTransaction());
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM probe')->fetchColumn());
    }

    #[Test]
    public function immediate_begin_commit_and_rollback_track_in_transaction(): void
    {
        $pdo = $this->open();
        $pdo->setPendingBeginMode(ImmediateAwareSqlitePdo::MODE_IMMEDIATE);
        $pdo->beginTransaction();
        $this->assertTrue($pdo->inTransaction());
        $this->assertTrue($pdo->immediateTransactionOpen());
        $pdo->exec("INSERT INTO probe (v) VALUES ('imm')");
        $pdo->commit();
        $this->assertFalse($pdo->inTransaction());
        $this->assertFalse($pdo->immediateTransactionOpen());
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM probe')->fetchColumn());
    }

    #[Test]
    public function pending_mode_clears_after_begin_attempt(): void
    {
        $pdo = $this->open();
        $pdo->setPendingBeginMode(ImmediateAwareSqlitePdo::MODE_IMMEDIATE);
        $pdo->beginTransaction();
        $this->assertSame(ImmediateAwareSqlitePdo::MODE_DEFERRED, $pdo->pendingBeginMode());
        $pdo->commit();
    }

    #[Test]
    public function failed_immediate_begin_against_held_writer_preserves_sqlite_busy(): void
    {
        $holder = $this->open();
        $holder->setPendingBeginMode(ImmediateAwareSqlitePdo::MODE_IMMEDIATE);
        $holder->beginTransaction();
        $holder->exec("INSERT INTO probe (v) VALUES ('hold')");

        $contender = $this->open();
        $contender->exec('PRAGMA busy_timeout = 50');
        $contender->setPendingBeginMode(ImmediateAwareSqlitePdo::MODE_IMMEDIATE);

        try {
            $contender->beginTransaction();
            $this->fail('Expected SQLITE_BUSY while writer holds the lock.');
        } catch (PDOException $e) {
            $driverCode = (int) ($e->errorInfo[1] ?? 0);
            $this->assertTrue(
                $driverCode === 5
                || $driverCode === 6
                || str_contains(strtolower($e->getMessage()), 'locked')
                || str_contains(strtolower($e->getMessage()), 'busy'),
                'Expected busy/locked failure, got: '.$e->getMessage().' code='.$driverCode,
            );
            $this->assertFalse($contender->inTransaction());
            $this->assertFalse($contender->immediateTransactionOpen());
            $this->assertSame(ImmediateAwareSqlitePdo::MODE_DEFERRED, $contender->pendingBeginMode());
        } finally {
            $holder->rollBack();
        }
    }

    #[Test]
    public function failed_commit_due_to_reader_keeps_immediate_transaction_open(): void
    {
        // DELETE journal: a live reader can make COMMIT return SQLITE_BUSY while the
        // writer transaction remains active (SQLite lang_transaction.html).
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'equity-4c1-commit-busy-'.bin2hex(random_bytes(8)).'.sqlite';
        touch($path);
        $writer = null;
        $reader = null;
        $stmt = null;

        try {
            $writer = new ImmediateAwareSqlitePdo('sqlite:'.$path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $writer->exec('PRAGMA journal_mode = DELETE');
            $writer->exec('PRAGMA busy_timeout = 50');
            $writer->exec('CREATE TABLE probe (id INTEGER PRIMARY KEY, v TEXT)');
            $writer->setPendingBeginMode(ImmediateAwareSqlitePdo::MODE_IMMEDIATE);
            $writer->beginTransaction();
            $writer->exec("INSERT INTO probe (v) VALUES ('w')");
            $this->assertTrue($writer->immediateTransactionOpen());

            $reader = new ImmediateAwareSqlitePdo('sqlite:'.$path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $reader->exec('PRAGMA busy_timeout = 50');
            $reader->exec('BEGIN');
            $stmt = $reader->query('SELECT * FROM probe');
            $this->assertNotFalse($stmt);

            try {
                $writer->commit();
                $this->fail('Expected COMMIT to fail with SQLITE_BUSY while reader is active.');
            } catch (PDOException $e) {
                $this->assertSame(5, (int) ($e->errorInfo[1] ?? 0), json_encode($e->errorInfo));
                // Must NOT silently clear — SQLite leaves the transaction active.
                $this->assertTrue($writer->immediateTransactionOpen());
                $this->assertTrue($writer->inTransaction());
                // Still writable inside the open txn.
                $writer->exec("INSERT INTO probe (v) VALUES ('after-failed-commit')");
            }

            $stmt = null;
            $reader->exec('ROLLBACK');
            $writer->rollBack();
            $this->assertFalse($writer->immediateTransactionOpen());
            $this->assertSame(0, (int) $writer->query('SELECT COUNT(*) FROM probe')->fetchColumn());
        } finally {
            // Release Windows file locks before unlink (PDO holds the sqlite handle).
            $stmt = null;
            $reader = null;
            $writer = null;
            gc_collect_cycles();
            foreach ([$path, $path.'-wal', $path.'-shm', $path.'-journal'] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    #[Test]
    public function failed_commit_with_no_active_transaction_clears_immediate_flag(): void
    {
        $pdo = $this->open();
        $pdo->setPendingBeginMode(ImmediateAwareSqlitePdo::MODE_IMMEDIATE);
        $pdo->beginTransaction();
        $pdo->commit();
        $this->assertFalse($pdo->immediateTransactionOpen());

        // Simulate a desynced flag after SQLite already ended the transaction.
        $flag = new \ReflectionProperty(ImmediateAwareSqlitePdo::class, 'immediateTransactionOpen');
        $flag->setAccessible(true);
        $flag->setValue($pdo, true);

        try {
            $pdo->commit();
            $this->fail('Expected COMMIT to fail with no active transaction.');
        } catch (PDOException $e) {
            $this->assertTrue(
                str_contains(strtolower($e->getMessage()), 'no transaction'),
                $e->getMessage(),
            );
            $this->assertFalse($pdo->immediateTransactionOpen());
            $this->assertFalse($pdo->inTransaction());
        }
    }
}
