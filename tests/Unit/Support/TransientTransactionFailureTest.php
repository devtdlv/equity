<?php

use App\Support\IdempotencyUniqueConstraint;
use App\Support\TransientTransactionFailure;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * @param  array{0?: string, 1?: int|string, 2?: string}  $errorInfo
 */
function makeTransientQueryException(
    string $sql,
    string $message,
    array $errorInfo,
    bool $uniqueSubclass = false,
): QueryException {
    $previous = new PDOException($message);
    $previous->errorInfo = [
        $errorInfo[0] ?? 'HY000',
        $errorInfo[1] ?? 0,
        $errorInfo[2] ?? $message,
    ];

    if ($uniqueSubclass) {
        return new UniqueConstraintViolationException('sqlite', $sql, [], $previous);
    }

    return new QueryException('sqlite', $sql, [], $previous);
}

test('mariadb deadlock 1213 is retryable', function (): void {
    $exception = makeTransientQueryException(
        sql: 'select * from `savings_goals` where `id` = 1 limit 1 for update',
        message: 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction',
        errorInfo: ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'],
    );

    expect(TransientTransactionFailure::isRetryable($exception))->toBeTrue()
        ->and(IdempotencyUniqueConstraint::isDuplicateKeyViolation($exception))->toBeFalse();
});

test('sqlstate 40001 without driver code is retryable', function (): void {
    $exception = makeTransientQueryException(
        sql: 'select 1',
        message: 'SQLSTATE[40001]: Serialization failure',
        errorInfo: ['40001', 0, 'Serialization failure'],
    );

    expect(TransientTransactionFailure::isRetryable($exception))->toBeTrue();
});

test('mariadb lock wait timeout 1205 is retryable', function (): void {
    $exception = makeTransientQueryException(
        sql: 'select * from `savings_goals` where `id` = 1 for update',
        message: 'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction',
        errorInfo: ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'],
    );

    expect(TransientTransactionFailure::isRetryable($exception))->toBeTrue()
        ->and(IdempotencyUniqueConstraint::isDuplicateKeyViolation($exception))->toBeFalse();
});

test('mariadb duplicate key 1062 is not a transient deadlock retry', function (): void {
    $exception = makeTransientQueryException(
        sql: 'insert into `savings_goal_events` (`idempotency_key`) values (?)',
        message: "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry for key 'savings_goal_events_user_id_idempotency_key_unique'",
        errorInfo: ['23000', 1062, "Duplicate entry for key 'savings_goal_events_user_id_idempotency_key_unique'"],
    );

    expect(TransientTransactionFailure::isRetryable($exception))->toBeFalse()
        ->and(IdempotencyUniqueConstraint::isDuplicateKeyViolation($exception))->toBeTrue();
});

test('unique constraint subclass is never treated as transient', function (): void {
    $exception = makeTransientQueryException(
        sql: 'insert into savings_goal_events',
        message: 'UNIQUE constraint failed',
        errorInfo: ['23000', 1062, 'Duplicate'],
        uniqueSubclass: true,
    );

    expect(TransientTransactionFailure::isRetryable($exception))->toBeFalse();
});

test('mariadb fk 1452 check 4025 and not null 1048 are not retryable', function (): void {
    $sql = 'insert into `savings_goal_events` (`user_id`, `idempotency_key`) values (?, ?)';

    $fk = makeTransientQueryException($sql, '1452 FK', ['23000', 1452, 'FK']);
    $check = makeTransientQueryException($sql, '4025 CHECK', ['23000', 4025, 'CHECK']);
    $notNull = makeTransientQueryException($sql, '1048 NOT NULL', ['23000', 1048, 'NOT NULL']);

    expect(TransientTransactionFailure::isRetryable($fk))->toBeFalse()
        ->and(TransientTransactionFailure::isRetryable($check))->toBeFalse()
        ->and(TransientTransactionFailure::isRetryable($notNull))->toBeFalse();
});

test('generic sqlite constraint 19 is not retryable', function (): void {
    $exception = makeTransientQueryException(
        sql: 'insert into "savings_goal_events"',
        message: 'SQLSTATE[23000]: Integrity constraint violation: 19 CONSTRAINT failed',
        errorInfo: ['23000', 19, 'CONSTRAINT failed'],
    );

    expect(TransientTransactionFailure::isRetryable($exception))->toBeFalse();
});

test('sqlite busy and locked codes are retryable', function (): void {
    $busy = makeTransientQueryException('select 1', 'database is locked', ['HY000', 5, 'database is locked']);
    $locked = makeTransientQueryException('select 1', 'table is locked', ['HY000', 6, 'table is locked']);

    expect(TransientTransactionFailure::isRetryable($busy))->toBeTrue()
        ->and(TransientTransactionFailure::isRetryable($locked))->toBeTrue();
});
