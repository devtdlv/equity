<?php

namespace App\Services;

use App\Enums\LedgerAccountClassification;
use App\Exceptions\LedgerIntegrityException;
use App\Models\Currency;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\IdempotencyUniqueConstraint;
use App\Support\TransientTransactionFailure;
use App\Support\TransientTransactionRetryBackoff;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Resolves or creates the per-user, per-currency system Opening Balances Equity ledger account.
 *
 * Does not post journal entries, change balances, or create user-visible financial accounts.
 *
 * Cooperating callers that wrap this method in an outer transaction must use the
 * same default database connection and lock currencies first, then ledger_accounts.
 * This service always takes a row lock on the currency before looking up or
 * inserting EQ-OPEN-{CCY}. Relocking a currency row already held on this
 * connection is expected.
 *
 * Duplicate-key INSERT is not recovered in-place. A failed INSERT does not leave
 * every engine in a usable transaction (PostgreSQL aborts the transaction; some
 * SQLite modes abort the statement or the transaction; MariaDB often continues).
 * UNIQUE (user_id, code) remains the final safeguard. When this service owns the
 * transaction, a matching unique violation rolls back and the attempt is retried.
 * When an outer transaction already exists, the exception is propagated so the
 * owner can roll back.
 */
class OpeningEquityAccountService
{
    public const CODE_PREFIX = 'EQ-OPEN-';

    public const CANONICAL_NAME = 'Opening Balances Equity';

    public const LEDGER_USER_CODE_UNIQUE_INDEX = 'ledger_accounts_user_id_code_unique';

    public const MAX_CONCURRENCY_ATTEMPTS = 3;

    public static function canonicalCode(string $currencyCode): string
    {
        return self::CODE_PREFIX.self::assertCanonicalCurrencyCode($currencyCode);
    }

    /**
     * Returns the existing Opening Balances Equity ledger account for this user and currency,
     * or creates it when none exists.
     */
    public function resolveOrCreate(User $user, Currency $currency): LedgerAccount
    {
        $this->assertValidUser($user);
        $currencyCode = self::assertCanonicalCurrencyCode((string) $currency->code);
        $code = self::canonicalCode($currencyCode);

        if (DB::transactionLevel() > 0) {
            return $this->resolveOrCreateInCurrentTransaction($user, $currencyCode, $code);
        }

        for ($attempt = 1; $attempt <= self::MAX_CONCURRENCY_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($user, $currencyCode, $code): LedgerAccount {
                    return $this->resolveOrCreateInCurrentTransaction($user, $currencyCode, $code);
                });
            } catch (QueryException $exception) {
                if (
                    $attempt < self::MAX_CONCURRENCY_ATTEMPTS
                    && $this->isOwnedTransactionRetryable($exception)
                ) {
                    TransientTransactionRetryBackoff::pauseBeforeRetry(
                        $exception,
                        $attempt,
                        self::MAX_CONCURRENCY_ATTEMPTS,
                    );

                    continue;
                }

                throw $exception;
            }
        }

        throw new LedgerIntegrityException(
            'Unable to resolve Opening Balances Equity due to concurrent contention.',
        );
    }

    private function resolveOrCreateInCurrentTransaction(
        User $user,
        string $currencyCode,
        string $code,
    ): LedgerAccount {
        $lockedCurrency = Currency::query()
            ->where('code', $currencyCode)
            ->lockForUpdate()
            ->first();

        if ($lockedCurrency === null) {
            throw new LedgerIntegrityException(sprintf(
                'Currency [%s] does not exist.',
                $currencyCode,
            ));
        }

        if ($lockedCurrency->code !== $currencyCode) {
            throw new LedgerIntegrityException(sprintf(
                'Currency [%s] is not stored as a canonical 3-letter ISO 4217 code.',
                $currencyCode,
            ));
        }

        $existing = LedgerAccount::query()
            ->where('user_id', $user->id)
            ->where('code', $code)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            $this->assertReusableOpeningEquity($existing, $user, $currencyCode, $code);

            return $existing;
        }

        if (! $lockedCurrency->is_active) {
            throw new LedgerIntegrityException(sprintf(
                'Currency [%s] is not an active supported currency.',
                $lockedCurrency->code,
            ));
        }

        return LedgerAccount::query()->create([
            'user_id' => $user->id,
            'financial_account_id' => null,
            'category_id' => null,
            'code' => $code,
            'name' => self::CANONICAL_NAME,
            'classification' => LedgerAccountClassification::Equity,
            'currency_code' => $lockedCurrency->code,
            'is_system' => true,
        ]);
    }

    private function assertReusableOpeningEquity(
        LedgerAccount $existing,
        User $user,
        string $currencyCode,
        string $code,
    ): void {
        $classification = $existing->classification instanceof LedgerAccountClassification
            ? $existing->classification
            : LedgerAccountClassification::tryFrom((string) $existing->classification);

        $isValid = (int) $existing->user_id === (int) $user->id
            && $existing->code === $code
            && $existing->currency_code === $currencyCode
            && $classification === LedgerAccountClassification::Equity
            && $existing->is_system === true
            && $existing->financial_account_id === null
            && $existing->category_id === null;

        if ($isValid) {
            return;
        }

        throw new LedgerIntegrityException(sprintf(
            'Ledger account [%s] already exists for this user but is not a valid Opening Balances Equity system account.',
            $code,
        ));
    }

    private function isOwnedTransactionRetryable(QueryException $exception): bool
    {
        return TransientTransactionFailure::isRetryable($exception)
            || $this->isLedgerUserCodeUniqueViolation($exception);
    }

    private function isLedgerUserCodeUniqueViolation(QueryException $exception): bool
    {
        if (! IdempotencyUniqueConstraint::isDuplicateKeyViolation($exception)) {
            return false;
        }

        $message = strtolower($exception->getMessage());
        $index = strtolower(self::LEDGER_USER_CODE_UNIQUE_INDEX);

        if (str_contains($message, $index)) {
            return true;
        }

        $uniqueFailed = str_contains($message, 'unique constraint failed')
            || str_contains($message, 'duplicate entry')
            || str_contains($message, 'unique violation');

        if (! $uniqueFailed) {
            return false;
        }

        return str_contains($message, 'ledger_accounts.user_id')
            && str_contains($message, 'ledger_accounts.code');
    }

    private static function assertCanonicalCurrencyCode(string $currencyCode): string
    {
        if (strlen($currencyCode) !== 3 || ! ctype_alpha($currencyCode) || ! ctype_upper($currencyCode)) {
            throw new LedgerIntegrityException(
                'Currency code must be a canonical 3-letter ISO 4217 code.',
            );
        }

        return $currencyCode;
    }

    private function assertValidUser(User $user): void
    {
        if (! $user->exists || $user->id === null) {
            throw new LedgerIntegrityException('Authenticated user is required.');
        }
    }
}
