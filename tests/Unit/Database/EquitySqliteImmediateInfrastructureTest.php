<?php

namespace Tests\Unit\Database;

use App\Database\SQLite\EquitySQLiteConnection;
use App\Database\SQLite\EquitySQLiteConnector;
use App\Database\SQLite\ImmediateAwareSqlitePdo;
use App\Exceptions\FinancialImmediateTransactionConflictException;
use App\Providers\DatabaseServiceProvider;
use App\Support\FinancialDatabaseTransaction;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Mockery;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class EquitySqliteImmediateInfrastructureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['equity.sqlite.financial_immediate' => false]);
    }

    protected function tearDown(): void
    {
        config(['equity.sqlite.financial_immediate' => false]);
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function connector_and_connection_resolver_hooks_are_active(): void
    {
        EquitySQLiteConnector::resetCreatePdoConnectionCalls();
        ImmediateAwareSqlitePdo::resetInstancesConstructed();

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $connection = DB::connection('sqlite');
        $this->assertInstanceOf(EquitySQLiteConnection::class, $connection);

        $beforeConnector = EquitySQLiteConnector::createPdoConnectionCalls();
        $beforePdo = ImmediateAwareSqlitePdo::instancesConstructed();

        $pdo = $connection->getPdo();
        $this->assertInstanceOf(ImmediateAwareSqlitePdo::class, $pdo);
        $this->assertGreaterThan($beforeConnector, EquitySQLiteConnector::createPdoConnectionCalls());
        $this->assertGreaterThan($beforePdo, ImmediateAwareSqlitePdo::instancesConstructed());
    }

    #[Test]
    public function sqlite_pragmas_remain_configured(): void
    {
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        $busy = DB::selectOne('PRAGMA busy_timeout');
        $busyTimeout = (int) ($busy->timeout ?? $busy->busy_timeout ?? -1);
        // Default phpunit / config may not force busy_timeout on :memory:; foreign_keys comes from config.
        $this->assertSame(1, (int) (DB::selectOne('PRAGMA foreign_keys')->foreign_keys ?? 0));
        config(['database.connections.sqlite.busy_timeout' => 5000]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        $busy = DB::selectOne('PRAGMA busy_timeout');
        $busyTimeout = (int) ($busy->timeout ?? $busy->busy_timeout ?? -1);
        $this->assertSame(5000, $busyTimeout);
        $this->assertInstanceOf(ImmediateAwareSqlitePdo::class, DB::connection()->getPdo());
    }

    #[Test]
    public function feature_flag_off_uses_deferred_outermost_begin(): void
    {
        config(['equity.sqlite.financial_immediate' => false]);
        /** @var EquitySQLiteConnection $connection */
        $connection = DB::connection();
        $this->assertInstanceOf(EquitySQLiteConnection::class, $connection);

        FinancialDatabaseTransaction::run(function () use ($connection): void {
            $this->assertSame(1, $connection->transactionLevel());
            $this->assertSame(EquitySQLiteConnection::MODE_DEFERRED, $connection->outermostBeginMode());
            $pdo = $connection->getPdo();
            $this->assertInstanceOf(ImmediateAwareSqlitePdo::class, $pdo);
            $this->assertFalse($pdo->immediateTransactionOpen());
            $this->assertTrue($pdo->inTransaction());
        });

        $this->assertSame(0, $connection->transactionLevel());
        $this->assertNull($connection->outermostBeginMode());
        $this->assertNull($connection->nextOutermostMode());
    }

    #[Test]
    public function feature_flag_on_uses_immediate_outermost_begin(): void
    {
        config(['equity.sqlite.financial_immediate' => true]);
        /** @var EquitySQLiteConnection $connection */
        $connection = DB::connection();

        FinancialDatabaseTransaction::run(function () use ($connection): void {
            $this->assertSame(EquitySQLiteConnection::MODE_IMMEDIATE, $connection->outermostBeginMode());
            $pdo = $connection->getPdo();
            $this->assertInstanceOf(ImmediateAwareSqlitePdo::class, $pdo);
            $this->assertTrue($pdo->immediateTransactionOpen());
            $this->assertTrue($pdo->inTransaction());
            DB::statement('CREATE TABLE IF NOT EXISTS equity_4c1_probe (id INTEGER PRIMARY KEY, v TEXT)');
            DB::table('equity_4c1_probe')->insert(['v' => 'ok']);
        });

        $this->assertNull($connection->outermostBeginMode());
        $this->assertNull($connection->nextOutermostMode());
        $this->assertSame(1, DB::table('equity_4c1_probe')->where('v', 'ok')->count());
    }

    #[Test]
    public function nested_savepoints_under_immediate_are_allowed(): void
    {
        config(['equity.sqlite.financial_immediate' => true]);
        /** @var EquitySQLiteConnection $connection */
        $connection = DB::connection();

        FinancialDatabaseTransaction::run(function () use ($connection): void {
            $this->assertSame(EquitySQLiteConnection::MODE_IMMEDIATE, $connection->outermostBeginMode());
            $this->assertSame(1, $connection->transactionLevel());

            FinancialDatabaseTransaction::run(function () use ($connection): void {
                $this->assertSame(2, $connection->transactionLevel());
                $this->assertSame(EquitySQLiteConnection::MODE_IMMEDIATE, $connection->outermostBeginMode());
                DB::statement('CREATE TABLE IF NOT EXISTS equity_4c1_nested (id INTEGER PRIMARY KEY)');
            });

            $this->assertSame(1, $connection->transactionLevel());
        });
    }

    #[Test]
    public function nested_deferred_outer_fail_closed_when_immediate_required(): void
    {
        config(['equity.sqlite.financial_immediate' => true]);
        /** @var EquitySQLiteConnection $connection */
        $connection = DB::connection();

        $this->expectException(FinancialImmediateTransactionConflictException::class);

        // Outer DEFERRED (plain DB::transaction), inner financial helper with flag ON.
        $connection->transaction(function () {
            FinancialDatabaseTransaction::run(function (): void {
                $this->fail('Nested financial IMMEDIATE must not run under DEFERRED outer.');
            });
        });
    }

    #[Test]
    public function mode_clears_after_exception_inside_immediate_transaction(): void
    {
        config(['equity.sqlite.financial_immediate' => true]);
        /** @var EquitySQLiteConnection $connection */
        $connection = DB::connection();

        try {
            FinancialDatabaseTransaction::run(function (): void {
                DB::statement('CREATE TABLE IF NOT EXISTS equity_4c1_boom (id INTEGER PRIMARY KEY)');
                throw new \RuntimeException('boom');
            });
            $this->fail('Expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(0, $connection->transactionLevel());
        $this->assertNull($connection->nextOutermostMode());
        $this->assertNull($connection->outermostBeginMode());
        $pdo = $connection->getPdo();
        $this->assertInstanceOf(ImmediateAwareSqlitePdo::class, $pdo);
        $this->assertFalse($pdo->inTransaction());
        $this->assertSame(ImmediateAwareSqlitePdo::MODE_DEFERRED, $pdo->pendingBeginMode());
    }

    #[Test]
    public function reconnection_produces_immediate_capable_pdo(): void
    {
        ImmediateAwareSqlitePdo::resetInstancesConstructed();
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        $first = DB::connection()->getPdo();
        $this->assertInstanceOf(ImmediateAwareSqlitePdo::class, $first);

        $countAfterFirst = ImmediateAwareSqlitePdo::instancesConstructed();
        DB::reconnect('sqlite');
        $second = DB::connection()->getPdo();
        $this->assertInstanceOf(ImmediateAwareSqlitePdo::class, $second);
        $this->assertGreaterThan($countAfterFirst, ImmediateAwareSqlitePdo::instancesConstructed());

        /** @var EquitySQLiteConnection $connection */
        $connection = DB::connection();
        $this->assertNull($connection->nextOutermostMode());
        $this->assertNull($connection->outermostBeginMode());
    }

    #[Test]
    public function repeated_transactions_do_not_leak_immediate_mode(): void
    {
        config(['equity.sqlite.financial_immediate' => true]);
        /** @var EquitySQLiteConnection $connection */
        $connection = DB::connection();

        FinancialDatabaseTransaction::run(function () use ($connection): void {
            $this->assertSame(EquitySQLiteConnection::MODE_IMMEDIATE, $connection->outermostBeginMode());
        });

        // Flag still on, but a plain transaction must be DEFERRED (no leftover request).
        $connection->transaction(function () use ($connection): void {
            $this->assertSame(EquitySQLiteConnection::MODE_DEFERRED, $connection->outermostBeginMode());
            $pdo = $connection->getPdo();
            $this->assertInstanceOf(ImmediateAwareSqlitePdo::class, $pdo);
            $this->assertFalse($pdo->immediateTransactionOpen());
        });

        config(['equity.sqlite.financial_immediate' => false]);
        FinancialDatabaseTransaction::run(function () use ($connection): void {
            $this->assertSame(EquitySQLiteConnection::MODE_DEFERRED, $connection->outermostBeginMode());
        });
    }

    #[Test]
    public function non_sqlite_driver_uses_plain_transaction_without_immediate(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        $connection->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(function (callable $callback) {
                return $callback();
            });

        $result = FinancialDatabaseTransaction::runOnConnection($connection, fn (): string => 'mysql-ok');
        $this->assertSame('mysql-ok', $result);
    }

    #[Test]
    public function begin_busy_is_translated_only_when_immediate_begin_failure_marker_is_set(): void
    {
        config(['equity.sqlite.financial_immediate' => true]);
        /** @var EquitySQLiteConnection $connection */
        $connection = DB::connection();

        $pdoException = new PDOException('SQLSTATE[HY000]: General error: 5 database is locked');
        $pdoException->errorInfo = ['HY000', 5, 'database is locked'];

        $method = new \ReflectionMethod(FinancialDatabaseTransaction::class, 'translateBeginBusyIfNeeded');
        $method->setAccessible(true);

        // No BEGIN IMMEDIATE failure marker → must not translate (unrelated lock).
        $this->assertFalse($connection->consumeImmediateBeginFailurePending());
        $unrelated = $method->invoke(null, $connection, $pdoException);
        $this->assertSame($pdoException, $unrelated);
        $this->assertNotInstanceOf(QueryException::class, $unrelated);

        // Simulate confirmed BEGIN IMMEDIATE failure marker.
        $setMarker = new \ReflectionProperty($connection, 'immediateBeginFailurePending');
        $setMarker->setAccessible(true);
        $setMarker->setValue($connection, true);

        /** @var QueryException|PDOException $translated */
        $translated = $method->invoke(null, $connection, $pdoException);
        $this->assertInstanceOf(QueryException::class, $translated);
        $this->assertSame(5, (int) ($translated->errorInfo[1] ?? 0));
        $this->assertSame($pdoException, $translated->getPrevious());
        $this->assertFalse($connection->consumeImmediateBeginFailurePending());
    }

    #[Test]
    public function unrelated_busy_inside_callback_is_not_translated_to_begin_query_exception(): void
    {
        config(['equity.sqlite.financial_immediate' => true]);

        $busy = new PDOException('SQLSTATE[HY000]: General error: 5 database is locked');
        $busy->errorInfo = ['HY000', 5, 'database is locked'];

        try {
            FinancialDatabaseTransaction::run(function () use ($busy): void {
                // BEGIN already succeeded; this is an unrelated lock-shaped PDOException.
                throw $busy;
            });
            $this->fail('Expected PDOException to propagate');
        } catch (QueryException $e) {
            $this->fail('Unrelated busy must not become a BEGIN QueryException: '.$e->getMessage());
        } catch (PDOException $e) {
            $this->assertSame($busy, $e);
            $this->assertSame(5, (int) ($e->errorInfo[1] ?? 0));
        }

        /** @var EquitySQLiteConnection $connection */
        $connection = DB::connection();
        $this->assertFalse($connection->consumeImmediateBeginFailurePending());
        $this->assertNull($connection->outermostBeginMode());
    }

    #[Test]
    public function begin_failure_marker_does_not_survive_run_on_connection_exit(): void
    {
        config(['equity.sqlite.financial_immediate' => true]);
        /** @var EquitySQLiteConnection $connection */
        $connection = DB::connection();
        $marker = new \ReflectionProperty($connection, 'immediateBeginFailurePending');
        $marker->setAccessible(true);

        // Success path clears a pre-seeded (stale) marker.
        $marker->setValue($connection, true);
        $this->assertSame('ok', FinancialDatabaseTransaction::run(fn (): string => 'ok'));
        $this->assertFalse($connection->consumeImmediateBeginFailurePending());

        // Non-PDO callback Throwable: catch (PDOException) does not run; finally must clear.
        $marker->setValue($connection, true);
        try {
            FinancialDatabaseTransaction::run(function (): void {
                throw new RuntimeException('callback boom');
            });
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('callback boom', $e->getMessage());
        }
        $this->assertFalse($connection->consumeImmediateBeginFailurePending());
        $this->assertNull($connection->outermostBeginMode());
        $this->assertNull($connection->nextOutermostMode());

        // Repeated financial transactions leave no latch between runs.
        FinancialDatabaseTransaction::run(function (): void {});
        FinancialDatabaseTransaction::run(function (): void {});
        $this->assertFalse($connection->consumeImmediateBeginFailurePending());

        // Reconnect clears the latch (setPdo).
        $marker->setValue($connection, true);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        /** @var EquitySQLiteConnection $reconnected */
        $reconnected = DB::connection();
        $this->assertFalse($reconnected->consumeImmediateBeginFailurePending());
    }

    #[Test]
    public function incompatible_connection_class_fails_closed_when_immediate_enabled(): void
    {
        config(['equity.sqlite.financial_immediate' => true]);

        $stock = Mockery::mock(Connection::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EquitySQLiteConnection');
        FinancialDatabaseTransaction::assertEquityImmediateInfrastructure($stock);
    }

    #[Test]
    public function plain_sqlite_connection_is_rejected_when_immediate_enabled(): void
    {
        config(['equity.sqlite.financial_immediate' => true]);

        $pdo = new ImmediateAwareSqlitePdo('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $plain = new SQLiteConnection($pdo, ':memory:', '', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'name' => 'sqlite',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EquitySQLiteConnection');
        FinancialDatabaseTransaction::runOnConnection($plain, fn (): null => null);
    }

    #[Test]
    public function provider_does_not_overwrite_existing_resolver_and_fails_closed_when_immediate_enabled(): void
    {
        $equityResolver = Connection::getResolver('sqlite');
        $this->assertNotNull($equityResolver);

        $foreignInvoked = false;
        Connection::resolverFor('sqlite', function ($connection, $database, $prefix, $config) use (&$foreignInvoked) {
            $foreignInvoked = true;

            return new SQLiteConnection($connection, $database, $prefix, $config);
        });

        try {
            $booting = [];
            $app = Mockery::mock(Application::class);
            $app->shouldReceive('bind')->once()->with('db.connector.sqlite', Mockery::type(\Closure::class));
            $app->shouldReceive('booting')->once()->andReturnUsing(function (callable $callback) use (&$booting): void {
                $booting[] = $callback;
            });

            config(['equity.sqlite.financial_immediate' => false]);
            (new DatabaseServiceProvider($app))->register();

            $this->assertCount(1, $booting);
            // Flag off: foreign resolver is left alone; boot check is a no-op.
            $booting[0]();
            $this->assertFalse($foreignInvoked);

            // Flag on: refuse boot rather than silently running IMMEDIATE under a foreign resolver.
            config(['equity.sqlite.financial_immediate' => true]);
            try {
                $booting[0]();
                $this->fail('Expected RuntimeException when financial_immediate is enabled under a foreign resolver');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('financial_immediate', $e->getMessage());
                $this->assertStringContainsString('resolver', strtolower($e->getMessage()));
            }

            // Equity resolver must not have been restored by the provider (no overwrite).
            DB::purge('sqlite');
            $conn = DB::connection('sqlite');
            $this->assertInstanceOf(SQLiteConnection::class, $conn);
            $this->assertNotInstanceOf(EquitySQLiteConnection::class, $conn);
            $this->assertTrue($foreignInvoked);
        } finally {
            Connection::resolverFor('sqlite', $equityResolver);
            config(['equity.sqlite.financial_immediate' => false]);
            DB::purge('sqlite');
            DB::reconnect('sqlite');
            $this->assertInstanceOf(EquitySQLiteConnection::class, DB::connection());
        }
    }

    #[Test]
    public function atomic_rollback_leaves_no_partial_rows_and_integrity_ok(): void
    {
        config(['equity.sqlite.financial_immediate' => true]);
        DB::statement('CREATE TABLE IF NOT EXISTS equity_4c1_atomic (id INTEGER PRIMARY KEY, v TEXT NOT NULL)');

        try {
            FinancialDatabaseTransaction::run(function (): void {
                DB::table('equity_4c1_atomic')->insert(['v' => 'partial']);
                $this->assertSame(1, DB::table('equity_4c1_atomic')->count());
                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, DB::table('equity_4c1_atomic')->count());
        $integrity = DB::selectOne('PRAGMA integrity_check');
        $value = is_object($integrity) ? (string) array_values((array) $integrity)[0] : '';
        $this->assertSame('ok', strtolower($value));
    }
}
