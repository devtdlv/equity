<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use InvalidArgumentException;

/**
 * Short, bounded sleep between transient concurrency retries after the failed
 * attempt's database transaction has fully rolled back.
 *
 * Delays stay tiny so the common path is unaffected and locks are never held
 * while sleeping. Unique-constraint / idempotency recovery paths should not
 * call {@see pauseBeforeRetry()} unless {@see TransientTransactionFailure::isRetryable()}
 * is true for the exception.
 */
final class TransientTransactionRetryBackoff
{
    /**
     * Base microseconds per failed attempt (attempt 1→2: 10ms, 2→3: 20ms).
     */
    public const BASE_MICROS_PER_ATTEMPT = 10_000;

    /**
     * Inclusive upper bound for additional random jitter in microseconds.
     */
    public const JITTER_MICROS_MAX = 15_000;

    /** @var (callable(int): void)|null */
    private static $sleeper = null;

    /**
     * Sleep before the next attempt when the failure is a confirmed transient
     * lock/deadlock and more attempts remain. No-op after the final attempt or
     * for non-retryable exceptions (including unique / idempotency races).
     */
    public static function pauseBeforeRetry(
        QueryException $exception,
        int $failedAttempt,
        int $maxAttempts,
    ): void {
        if ($failedAttempt < 1) {
            throw new InvalidArgumentException('failedAttempt must be at least 1.');
        }

        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('maxAttempts must be at least 1.');
        }

        // Never sleep after the final failed attempt (or when none remain).
        if ($failedAttempt >= $maxAttempts) {
            return;
        }

        if (! TransientTransactionFailure::isRetryable($exception)) {
            return;
        }

        self::sleep(self::delayMicros($failedAttempt));
    }

    /**
     * Deterministic delay band for a failed attempt number (before jitter application
     * in {@see sleep()}; exposed for unit assertions).
     *
     * @return array{0: int, 1: int} Inclusive [min, max] microseconds.
     */
    public static function delayMicrosRange(int $failedAttempt): array
    {
        if ($failedAttempt < 1) {
            throw new InvalidArgumentException('failedAttempt must be at least 1.');
        }

        $base = self::BASE_MICROS_PER_ATTEMPT * $failedAttempt;

        return [$base, $base + self::JITTER_MICROS_MAX];
    }

    public static function delayMicros(int $failedAttempt): int
    {
        [$min, $max] = self::delayMicrosRange($failedAttempt);

        return random_int($min, $max);
    }

    /**
     * @param  (callable(int): void)|null  $sleeper
     */
    public static function setSleeper(?callable $sleeper): void
    {
        self::$sleeper = $sleeper;
    }

    public static function resetSleeper(): void
    {
        self::$sleeper = null;
    }

    private static function sleep(int $micros): void
    {
        $sleeper = self::$sleeper ?? static function (int $value): void {
            usleep($value);
        };

        $sleeper($micros);
    }
}
