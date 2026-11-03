<?php

use App\Support\TransientTransactionFailure;
use App\Support\TransientTransactionRetryBackoff;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * @param  array{0?: string, 1?: int|string, 2?: string}  $errorInfo
 */
function makeBackoffQueryException(
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
        return new UniqueConstraintViolationException('sqlite', 'insert into "journal_entries"', [], $previous);
    }

    return new QueryException('sqlite', 'insert into "journal_entries"', [], $previous);
}

afterEach(function (): void {
    TransientTransactionRetryBackoff::resetSleeper();
});

test('delay micros range grows with failed attempt and stays bounded', function (): void {
    expect(TransientTransactionRetryBackoff::delayMicrosRange(1))->toBe([10_000, 25_000])
        ->and(TransientTransactionRetryBackoff::delayMicrosRange(2))->toBe([20_000, 35_000]);

    $sample = TransientTransactionRetryBackoff::delayMicros(1);
    expect($sample)->toBeGreaterThanOrEqual(10_000)
        ->and($sample)->toBeLessThanOrEqual(25_000);
});

test('pauseBeforeRetry sleeps between retryable attempts only', function (): void {
    $slept = [];
    TransientTransactionRetryBackoff::setSleeper(function (int $micros) use (&$slept): void {
        $slept[] = $micros;
    });

    $busy = makeBackoffQueryException('database is locked', ['HY000', TransientTransactionFailure::SQLITE_BUSY, 'database is locked']);

    TransientTransactionRetryBackoff::pauseBeforeRetry($busy, 1, 3);
    TransientTransactionRetryBackoff::pauseBeforeRetry($busy, 2, 3);

    expect($slept)->toHaveCount(2)
        ->and($slept[0])->toBeGreaterThanOrEqual(10_000)->toBeLessThanOrEqual(25_000)
        ->and($slept[1])->toBeGreaterThanOrEqual(20_000)->toBeLessThanOrEqual(35_000);
});

test('pauseBeforeRetry does not sleep after the final attempt', function (): void {
    $slept = [];
    TransientTransactionRetryBackoff::setSleeper(function (int $micros) use (&$slept): void {
        $slept[] = $micros;
    });

    $busy = makeBackoffQueryException('database is locked', ['HY000', TransientTransactionFailure::SQLITE_BUSY, 'database is locked']);

    TransientTransactionRetryBackoff::pauseBeforeRetry($busy, 3, 3);

    expect($slept)->toBe([]);
});

test('pauseBeforeRetry does not sleep for non-retryable exceptions', function (): void {
    $slept = [];
    TransientTransactionRetryBackoff::setSleeper(function (int $micros) use (&$slept): void {
        $slept[] = $micros;
    });

    $unique = makeBackoffQueryException(
        'UNIQUE constraint failed: journal_entries.user_id, journal_entries.idempotency_key',
        ['23000', 19, 'UNIQUE constraint failed'],
        uniqueSubclass: true,
    );
    $fk = makeBackoffQueryException(
        'FOREIGN KEY constraint failed',
        ['23000', 19, 'FOREIGN KEY constraint failed'],
    );

    TransientTransactionRetryBackoff::pauseBeforeRetry($unique, 1, 3);
    TransientTransactionRetryBackoff::pauseBeforeRetry($fk, 1, 3);

    expect(TransientTransactionFailure::isRetryable($unique))->toBeFalse()
        ->and(TransientTransactionFailure::isRetryable($fk))->toBeFalse()
        ->and($slept)->toBe([]);
});
