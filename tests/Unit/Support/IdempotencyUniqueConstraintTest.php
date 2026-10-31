<?php

use App\Support\IdempotencyUniqueConstraint;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * @param  array{0?: string, 1?: int|string, 2?: string}  $errorInfo
 */
function makeQueryException(
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

test('mariadb duplicate key 1062 for savings goal event index is classified as idempotency', function (): void {
    $sql = 'insert into `savings_goal_events` (`user_id`, `idempotency_key`, `type`) values (?, ?, ?)';
    $exception = makeQueryException(
        sql: $sql,
        message: "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1-uuid' for key 'savings_goal_events_user_id_idempotency_key_unique'",
        errorInfo: ['23000', 1062, "Duplicate entry '1-uuid' for key 'savings_goal_events_user_id_idempotency_key_unique'"],
    );

    expect(IdempotencyUniqueConstraint::matches(
        $exception,
        'savings_goal_events_user_id_idempotency_key_unique',
        'savings_goal_events',
    ))->toBeTrue();
});

test('mariadb foreign key 1452 is not idempotency even when SQL lists idempotency_key', function (): void {
    $sql = 'insert into `savings_goal_events` (`user_id`, `savings_goal_id`, `idempotency_key`, `type`, `amount_minor`, `currency_code`) values (1, 999, ?, ?, ?, ?)';
    $exception = makeQueryException(
        sql: $sql,
        message: 'SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row: a foreign key constraint fails (`equity`.`savings_goal_events`, CONSTRAINT `savings_goal_events_goal_user_currency_foreign` FOREIGN KEY (`savings_goal_id`, `user_id`, `currency_code`) REFERENCES `savings_goals` (`id`, `user_id`, `currency_code`)) (Connection: mariadb, SQL: '.$sql.')',
        errorInfo: ['23000', 1452, 'Cannot add or update a child row'],
    );

    // Primary regression: old logic matched solely on "idempotency_key" in the SQL/message.
    expect(str_contains(strtolower($exception->getMessage()), 'idempotency_key'))->toBeTrue()
        ->and(IdempotencyUniqueConstraint::isDuplicateKeyViolation($exception))->toBeFalse()
        ->and(IdempotencyUniqueConstraint::matches(
            $exception,
            'savings_goal_events_user_id_idempotency_key_unique',
            'savings_goal_events',
        ))->toBeFalse();
});

test('mariadb check constraint 4025 is not idempotency even when SQL lists idempotency_key', function (): void {
    $sql = 'insert into `savings_goal_events` (`idempotency_key`, `amount_minor`) values (?, 0)';
    $exception = makeQueryException(
        sql: $sql,
        message: 'SQLSTATE[23000]: Integrity constraint violation: 4025 CONSTRAINT `chk_savings_goal_events_integrity` failed (Connection: mariadb, SQL: '.$sql.')',
        errorInfo: ['23000', 4025, 'CONSTRAINT `chk_savings_goal_events_integrity` failed'],
    );

    expect(str_contains(strtolower($exception->getMessage()), 'idempotency_key'))->toBeTrue()
        ->and(IdempotencyUniqueConstraint::matches(
            $exception,
            'savings_goal_events_user_id_idempotency_key_unique',
            'savings_goal_events',
        ))->toBeFalse();
});

test('sqlite extended unique code 2067 is classified while generic constraint 19 is not', function (): void {
    $uniqueSql = 'insert into "savings_goal_events" ("user_id", "idempotency_key") values (1, ?)';
    $unique = makeQueryException(
        sql: $uniqueSql,
        message: 'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: savings_goal_events.user_id, savings_goal_events.idempotency_key (Connection: sqlite, SQL: '.$uniqueSql.')',
        errorInfo: ['23000', 2067, 'UNIQUE constraint failed: savings_goal_events.user_id, savings_goal_events.idempotency_key'],
    );

    expect(IdempotencyUniqueConstraint::matches(
        $unique,
        'savings_goal_events_user_id_idempotency_key_unique',
        'savings_goal_events',
    ))->toBeTrue();

    $fkSql = 'insert into "savings_goal_events" ("user_id", "savings_goal_id", "idempotency_key") values (1, 999, ?)';
    $fk = makeQueryException(
        sql: $fkSql,
        message: 'SQLSTATE[23000]: Integrity constraint violation: 19 FOREIGN KEY constraint failed (Connection: sqlite, SQL: '.$fkSql.')',
        errorInfo: ['23000', 19, 'FOREIGN KEY constraint failed'],
    );

    expect(str_contains(strtolower($fk->getMessage()), 'idempotency_key'))->toBeTrue()
        ->and(IdempotencyUniqueConstraint::isDuplicateKeyViolation($fk))->toBeFalse()
        ->and(IdempotencyUniqueConstraint::matches(
            $fk,
            'savings_goal_events_user_id_idempotency_key_unique',
            'savings_goal_events',
        ))->toBeFalse();
});

test('lifecycle goal index classification mirrors event classification rules', function (): void {
    $dup = makeQueryException(
        sql: 'insert into `savings_goals` (`user_id`, `idempotency_key`, `create_request_fingerprint`) values (?, ?, ?)',
        message: "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1-uuid' for key 'savings_goals_user_id_idempotency_key_unique'",
        errorInfo: ['23000', 1062, "Duplicate entry '1-uuid' for key 'savings_goals_user_id_idempotency_key_unique'"],
    );

    expect(IdempotencyUniqueConstraint::matches(
        $dup,
        'savings_goals_user_id_idempotency_key_unique',
        'savings_goals',
    ))->toBeTrue();

    $notNull = makeQueryException(
        sql: 'insert into `savings_goals` (`user_id`, `idempotency_key`, `create_request_fingerprint`) values (?, ?, null)',
        message: 'SQLSTATE[23000]: Integrity constraint violation: 1048 Column \'create_request_fingerprint\' cannot be null (Connection: mariadb, SQL: insert into `savings_goals` (`user_id`, `idempotency_key`, `create_request_fingerprint`) values (?, ?, null))',
        errorInfo: ['23000', 1048, "Column 'create_request_fingerprint' cannot be null"],
    );

    expect(str_contains(strtolower($notNull->getMessage()), 'idempotency_key'))->toBeTrue()
        ->and(IdempotencyUniqueConstraint::matches(
            $notNull,
            'savings_goals_user_id_idempotency_key_unique',
            'savings_goals',
        ))->toBeFalse();
});

test('unique subclass without matching index is rejected conservatively', function (): void {
    $exception = makeQueryException(
        sql: 'insert into "savings_goal_events" ("id") values (1)',
        message: 'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: savings_goal_events.id',
        errorInfo: ['23000', 2067, 'UNIQUE constraint failed: savings_goal_events.id'],
        uniqueSubclass: true,
    );

    expect(IdempotencyUniqueConstraint::isDuplicateKeyViolation($exception))->toBeTrue()
        ->and(IdempotencyUniqueConstraint::matches(
            $exception,
            'savings_goal_events_user_id_idempotency_key_unique',
            'savings_goal_events',
        ))->toBeFalse();
});
