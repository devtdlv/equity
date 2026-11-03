<?php

namespace App\Services;

use App\DataTransferObjects\CreatedFinancialAccountResult;
use App\DataTransferObjects\PostOpeningBalanceInput;
use App\Enums\FinancialAccountType;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\ImmutableRecordException;
use App\Exceptions\InactiveCurrencyException;
use App\Exceptions\LedgerIntegrityException;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\IdempotencyUniqueConstraint;
use App\Support\MoneyInputParser;
use App\Support\TransientTransactionFailure;
use App\Support\TransientTransactionRetryBackoff;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinancialAccountService
{
    public const OPENING_IDEMPOTENCY_KEY_PREFIX = 'opening-balance:account:';

    public const IDEMPOTENCY_UNIQUE_INDEX = 'financial_accounts_user_id_idempotency_key_unique';

    public const MAX_CONCURRENCY_ATTEMPTS = 3;

    public function __construct(
        private readonly AuditService $auditService,
        private readonly OpeningBalanceService $openingBalanceService,
    ) {}

    /**
     * Atomically creates a user-visible financial account, its 1:1 ledger account, an audit event,
     * and an optional opening-balance journal.
     *
     * Create idempotency is scoped to `(user_id, idempotency_key)` with a durable
     * `create_request_fingerprint`. Matching retries return the existing account without a second
     * audit event, ledger row, or opening journal. Conflicting payloads throw
     * `IdempotencyConflictException`.
     *
     * Lock order on insert: insert financial_accounts (the insert holds the new row), insert
     * ledger_accounts without a ledger row lock, then lock currencies. The starting amount is
     * parsed again against the locked currency exponent before posting. OpeningBalanceService
     * later locks the new financial account and re-locks the currency. The account ledger is
     * never locked before currency. Idempotent replay may `lockForUpdate` the existing account
     * row by `(user_id, idempotency_key)` before returning.
     *
     * @param  array{
     *     name: string,
     *     type: FinancialAccountType|string,
     *     currency_code: string,
     *     institution?: string|null,
     *     description?: string|null,
     *     starting_balance?: string|null,
     *     idempotency_key?: string|null
     * }  $data
     */
    public function createAccount(User $user, array $data): FinancialAccount
    {
        return $this->createAccountResult($user, $data)->account;
    }

    /**
     * @param  array{
     *     name: string,
     *     type: FinancialAccountType|string,
     *     currency_code: string,
     *     institution?: string|null,
     *     description?: string|null,
     *     starting_balance?: string|null,
     *     idempotency_key?: string|null
     * }  $data
     */
    public function createAccountResult(User $user, array $data): CreatedFinancialAccountResult
    {
        $accountType = $data['type'] instanceof FinancialAccountType
            ? $data['type']
            : FinancialAccountType::from((string) $data['type']);

        $currencyCode = strtoupper(trim((string) $data['currency_code']));
        $trimmedName = trim($data['name']);
        $institution = isset($data['institution']) && is_string($data['institution']) && trim($data['institution']) !== ''
            ? trim($data['institution'])
            : null;
        $description = isset($data['description']) && is_string($data['description']) && trim($data['description']) !== ''
            ? trim($data['description'])
            : null;
        $startingBalance = $this->optionalStartingBalance($data);
        $idempotencyKey = $this->resolveCreateIdempotencyKey($data);

        // Resolve currency before fingerprinting so equivalent major-unit spellings
        // (including blank/zero openings) canonicalize to the same signed minor amount.
        $currency = $this->findActiveCurrency($currencyCode);
        $startingAmountMinor = $this->parseOptionalStartingBalanceMinor(
            $startingBalance,
            (int) $currency->minor_unit_exponent,
        );
        $fingerprint = $this->createRequestFingerprint(
            name: $trimmedName,
            type: $accountType->value,
            currencyCode: $currency->code,
            institution: $institution,
            description: $description,
            startingAmountMinor: $startingAmountMinor,
        );

        $existing = $this->findByCreateIdempotencyKey($user, $idempotencyKey);

        if ($existing !== null) {
            return $this->verifyExistingCreateMatch($existing, $fingerprint);
        }

        // Always open a transaction (or savepoint when nested) so account, ledger,
        // audit, and optional opening post as one atomic unit. Retry only when this
        // method owns the outermost transaction; never retry inside a caller-owned txn.
        $ownsOutermostTransaction = DB::transactionLevel() === 0;

        if (! $ownsOutermostTransaction) {
            try {
                return DB::transaction(function () use (
                    $user,
                    $accountType,
                    $currencyCode,
                    $trimmedName,
                    $institution,
                    $description,
                    $startingBalance,
                    $idempotencyKey,
                    $fingerprint,
                ): CreatedFinancialAccountResult {
                    return $this->createAccountInCurrentTransaction(
                        user: $user,
                        accountType: $accountType,
                        currencyCode: $currencyCode,
                        trimmedName: $trimmedName,
                        institution: $institution,
                        description: $description,
                        startingBalance: $startingBalance,
                        idempotencyKey: $idempotencyKey,
                        fingerprint: $fingerprint,
                    );
                });
            } catch (UniqueConstraintViolationException $exception) {
                return $this->recoverFromIdempotencyRace($exception, $user, $idempotencyKey, $fingerprint);
            } catch (QueryException $exception) {
                return $this->recoverFromIdempotencyRace($exception, $user, $idempotencyKey, $fingerprint);
            }
        }

        for ($attempt = 1; $attempt <= self::MAX_CONCURRENCY_ATTEMPTS; $attempt++) {
            $existing = $this->findByCreateIdempotencyKey($user, $idempotencyKey);

            if ($existing !== null) {
                return $this->verifyExistingCreateMatch($existing, $fingerprint);
            }

            try {
                return DB::transaction(function () use (
                    $user,
                    $accountType,
                    $currencyCode,
                    $trimmedName,
                    $institution,
                    $description,
                    $startingBalance,
                    $idempotencyKey,
                    $fingerprint,
                ): CreatedFinancialAccountResult {
                    return $this->createAccountInCurrentTransaction(
                        user: $user,
                        accountType: $accountType,
                        currencyCode: $currencyCode,
                        trimmedName: $trimmedName,
                        institution: $institution,
                        description: $description,
                        startingBalance: $startingBalance,
                        idempotencyKey: $idempotencyKey,
                        fingerprint: $fingerprint,
                    );
                });
            } catch (UniqueConstraintViolationException $exception) {
                if ($this->isCreateIdempotencyUniqueViolation($exception)) {
                    $raced = $this->findByCreateIdempotencyKey($user, $idempotencyKey);

                    if ($raced !== null) {
                        return $this->verifyExistingCreateMatch($raced, $fingerprint);
                    }
                }

                throw $exception;
            } catch (QueryException $exception) {
                if ($this->isCreateIdempotencyUniqueViolation($exception)) {
                    $raced = $this->findByCreateIdempotencyKey($user, $idempotencyKey);

                    if ($raced !== null) {
                        return $this->verifyExistingCreateMatch($raced, $fingerprint);
                    }
                }

                if (
                    $attempt < self::MAX_CONCURRENCY_ATTEMPTS
                    && TransientTransactionFailure::isRetryable($exception)
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
            'Unable to create the financial account due to concurrent contention.',
        );
    }

    private function createAccountInCurrentTransaction(
        User $user,
        FinancialAccountType $accountType,
        string $currencyCode,
        string $trimmedName,
        ?string $institution,
        ?string $description,
        ?string $startingBalance,
        string $idempotencyKey,
        string $fingerprint,
    ): CreatedFinancialAccountResult {
        $lockedExisting = FinancialAccount::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();

        if ($lockedExisting !== null) {
            return $this->verifyExistingCreateMatch($lockedExisting, $fingerprint);
        }

        $currency = $this->findActiveCurrency($currencyCode);
        $this->parseOptionalStartingBalanceMinor(
            $startingBalance,
            (int) $currency->minor_unit_exponent,
        );

        $financialAccount = FinancialAccount::query()->create([
            'user_id' => $user->id,
            'idempotency_key' => $idempotencyKey,
            'create_request_fingerprint' => $fingerprint,
            'name' => $trimmedName,
            'type' => $accountType,
            'currency_code' => $currency->code,
            'institution' => $institution,
            'description' => $description,
            'is_archived' => false,
            'archived_at' => null,
        ]);

        $ledgerAccount = LedgerAccount::query()->create([
            'user_id' => $user->id,
            'financial_account_id' => $financialAccount->id,
            'code' => sprintf('ACCT-%d', $financialAccount->id),
            'name' => $trimmedName,
            'classification' => $accountType->ledgerClassification(),
            'currency_code' => $currency->code,
            'is_system' => false,
        ]);

        $this->auditService->record(
            actor: $user,
            action: AuditService::ACTION_ACCOUNT_CREATED,
            auditableType: FinancialAccount::class,
            auditableId: $financialAccount->id,
            metadata: [
                'ledger_account_id' => $ledgerAccount->id,
                'type' => $accountType->value,
                'currency_code' => $currency->code,
                'idempotency_key' => $idempotencyKey,
            ],
        );

        $lockedCurrency = $this->lockActiveCurrency($currency->code);
        $amountMinor = $this->parseOptionalStartingBalanceMinor(
            $startingBalance,
            (int) $lockedCurrency->minor_unit_exponent,
        );

        if ($amountMinor !== 0) {
            $this->openingBalanceService->postForAccount(new PostOpeningBalanceInput(
                user: $user,
                financialAccountId: (int) $financialAccount->id,
                amountMinor: $amountMinor,
                entryDate: now('UTC')->format('Y-m-d'),
                idempotencyKey: self::OPENING_IDEMPOTENCY_KEY_PREFIX.$financialAccount->id,
            ));
        }

        return new CreatedFinancialAccountResult(
            account: $financialAccount->load(['currency', 'ledgerAccount']),
            wasRecentlyCreated: true,
        );
    }

    private function recoverFromIdempotencyRace(
        QueryException $exception,
        User $user,
        string $idempotencyKey,
        string $fingerprint,
    ): CreatedFinancialAccountResult {
        if (! $this->isCreateIdempotencyUniqueViolation($exception)) {
            throw $exception;
        }

        $raced = $this->findByCreateIdempotencyKey($user, $idempotencyKey);

        if ($raced === null) {
            throw $exception;
        }

        return $this->verifyExistingCreateMatch($raced, $fingerprint);
    }

    private function findByCreateIdempotencyKey(User $user, string $idempotencyKey): ?FinancialAccount
    {
        return FinancialAccount::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    private function verifyExistingCreateMatch(
        FinancialAccount $existing,
        string $fingerprint,
    ): CreatedFinancialAccountResult {
        if (! hash_equals((string) $existing->create_request_fingerprint, $fingerprint)) {
            throw new IdempotencyConflictException(
                'Financial account idempotency key was reused with a different payload.',
            );
        }

        return new CreatedFinancialAccountResult(
            account: $existing->relationLoaded('currency') && $existing->relationLoaded('ledgerAccount')
                ? $existing
                : ($existing->fresh(['currency', 'ledgerAccount']) ?? $existing),
            wasRecentlyCreated: false,
        );
    }

    private function createRequestFingerprint(
        string $name,
        string $type,
        string $currencyCode,
        ?string $institution,
        ?string $description,
        int $startingAmountMinor,
    ): string {
        return hash('sha256', json_encode([
            'name' => $name,
            'type' => $type,
            'currency_code' => $currencyCode,
            'institution' => $institution,
            'description' => $description,
            'starting_amount_minor' => $startingAmountMinor,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array{idempotency_key?: string|null}  $data
     */
    private function resolveCreateIdempotencyKey(array $data): string
    {
        if (! array_key_exists('idempotency_key', $data) || $data['idempotency_key'] === null) {
            return strtolower((string) Str::uuid());
        }

        if (! is_string($data['idempotency_key'])) {
            throw new LedgerIntegrityException('Financial account idempotency key must be a valid UUID.');
        }

        $normalized = strtolower(trim($data['idempotency_key']));

        if (! Str::isUuid($normalized)) {
            throw new LedgerIntegrityException('Financial account idempotency key must be a valid UUID.');
        }

        return $normalized;
    }

    private function isCreateIdempotencyUniqueViolation(QueryException $exception): bool
    {
        return IdempotencyUniqueConstraint::matches(
            $exception,
            self::IDEMPOTENCY_UNIQUE_INDEX,
            'financial_accounts',
        );
    }

    /**
     * Updates a user-visible financial account and keeps its linked ledger account synchronized.
     *
     * Acquires row locks in the global lock hierarchy (financial_accounts -> currencies -> ledger_accounts)
     * before checking for posted ledger activity so concurrent postings cannot race with a currency
     * or classification change and cannot deadlock via lock-order inversion.
     *
     * @param  array{
     *     name: string,
     *     type: FinancialAccountType|string,
     *     currency_code?: string,
     *     institution?: string|null,
     *     description?: string|null
     * }  $data
     */
    public function updateAccount(User $actor, FinancialAccount $account, array $data): FinancialAccount
    {
        $this->assertOwnership($actor, $account);

        $accountType = $data['type'] instanceof FinancialAccountType
            ? $data['type']
            : FinancialAccountType::from((string) $data['type']);

        $explicitCurrencyCode = isset($data['currency_code'])
            ? strtoupper(trim($data['currency_code']))
            : null;

        $trimmedName = trim($data['name']);
        $institution = array_key_exists('institution', $data) && is_string($data['institution']) && trim($data['institution']) !== ''
            ? trim($data['institution'])
            : null;
        $description = array_key_exists('description', $data) && is_string($data['description']) && trim($data['description']) !== ''
            ? trim($data['description'])
            : null;

        return DB::transaction(function () use (
            $actor,
            $account,
            $accountType,
            $explicitCurrencyCode,
            $trimmedName,
            $institution,
            $description
        ): FinancialAccount {
            // 1. Lock and reload the financial account first
            /** @var FinancialAccount $lockedAccount */
            $lockedAccount = FinancialAccount::query()
                ->whereKey($account->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertOwnership($actor, $lockedAccount);

            // 2. Determine the requested currency using the locked, current account state
            $requestedCurrencyCode = $explicitCurrencyCode ?? $lockedAccount->currency_code;
            $isChangingCurrency = $requestedCurrencyCode !== $lockedAccount->currency_code;

            // 3. When currency is changing, lock and validate the Currency row BEFORE locking LedgerAccount
            // so lock acquisition follows financial_accounts -> currencies -> ledger_accounts
            if ($isChangingCurrency) {
                $this->resolveActiveCurrency($requestedCurrencyCode);
            }

            // 4. Lock the linked LedgerAccount afterward
            /** @var LedgerAccount $ledgerAccount */
            $ledgerAccount = LedgerAccount::query()
                ->where('financial_account_id', $lockedAccount->id)
                ->lockForUpdate()
                ->firstOrFail();

            $hasPostedActivity = $ledgerAccount->postings()->exists();

            if ($isChangingCurrency && $hasPostedActivity) {
                throw new ImmutableRecordException(
                    'Financial account currency cannot be changed once ledger activity exists.',
                );
            }

            if (
                $hasPostedActivity
                && $accountType->ledgerClassification() !== $lockedAccount->type->ledgerClassification()
            ) {
                throw new ImmutableRecordException(
                    'Account type cannot switch between asset and liability classifications once ledger activity exists.',
                );
            }

            $lockedAccount->fill([
                'name' => $trimmedName,
                'type' => $accountType,
                'currency_code' => $requestedCurrencyCode,
                'institution' => $institution,
                'description' => $description,
            ]);

            $changedFields = array_keys($lockedAccount->getDirty());
            $lockedAccount->save();

            $ledgerAccount->fill([
                'name' => $trimmedName,
                'classification' => $accountType->ledgerClassification(),
                'currency_code' => $requestedCurrencyCode,
            ]);
            $ledgerAccount->save();

            $this->auditService->record(
                actor: $actor,
                action: AuditService::ACTION_ACCOUNT_UPDATED,
                auditableType: FinancialAccount::class,
                auditableId: $lockedAccount->id,
                metadata: [
                    'changed_fields' => $changedFields,
                    'type' => $accountType->value,
                    'currency_code' => $requestedCurrencyCode,
                ],
            );

            return $lockedAccount->load(['currency', 'ledgerAccount']);
        });
    }

    /**
     * Archives a financial account inside a locked transaction so stale model instances
     * cannot overwrite newer fields or emit duplicate archive audit events.
     */
    public function archiveAccount(User $actor, FinancialAccount $account): FinancialAccount
    {
        $this->assertOwnership($actor, $account);

        return DB::transaction(function () use ($actor, $account): FinancialAccount {
            /** @var FinancialAccount $lockedAccount */
            $lockedAccount = FinancialAccount::query()
                ->whereKey($account->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertOwnership($actor, $lockedAccount);

            if ($lockedAccount->is_archived) {
                return $lockedAccount->load(['currency', 'ledgerAccount']);
            }

            $lockedAccount->update([
                'is_archived' => true,
                'archived_at' => now(),
            ]);

            $this->auditService->record(
                actor: $actor,
                action: AuditService::ACTION_ACCOUNT_ARCHIVED,
                auditableType: FinancialAccount::class,
                auditableId: $lockedAccount->id,
                metadata: [
                    'currency_code' => $lockedAccount->currency_code,
                ],
            );

            return $lockedAccount->load(['currency', 'ledgerAccount']);
        });
    }

    /**
     * Restores an archived financial account inside a locked transaction so stale model instances
     * cannot overwrite newer fields or emit duplicate restore audit events.
     */
    public function restoreAccount(User $actor, FinancialAccount $account): FinancialAccount
    {
        $this->assertOwnership($actor, $account);

        return DB::transaction(function () use ($actor, $account): FinancialAccount {
            /** @var FinancialAccount $lockedAccount */
            $lockedAccount = FinancialAccount::query()
                ->whereKey($account->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertOwnership($actor, $lockedAccount);

            if (! $lockedAccount->is_archived) {
                return $lockedAccount->load(['currency', 'ledgerAccount']);
            }

            $lockedAccount->update([
                'is_archived' => false,
                'archived_at' => null,
            ]);

            $this->auditService->record(
                actor: $actor,
                action: AuditService::ACTION_ACCOUNT_RESTORED,
                auditableType: FinancialAccount::class,
                auditableId: $lockedAccount->id,
                metadata: [
                    'currency_code' => $lockedAccount->currency_code,
                ],
            );

            return $lockedAccount->load(['currency', 'ledgerAccount']);
        });
    }

    private function optionalStartingBalance(array $data): ?string
    {
        if (! array_key_exists('starting_balance', $data) || $data['starting_balance'] === null) {
            return null;
        }

        if (! is_string($data['starting_balance'])) {
            return null;
        }

        $trimmed = trim($data['starting_balance']);

        return $trimmed === '' ? null : $trimmed;
    }

    private function parseOptionalStartingBalanceMinor(?string $startingBalance, int $exponent): int
    {
        if ($startingBalance === null) {
            return 0;
        }

        return MoneyInputParser::parseSignedToMinorUnits($startingBalance, $exponent);
    }

    private function findActiveCurrency(string $currencyCode): Currency
    {
        $currency = Currency::query()
            ->where('code', $currencyCode)
            ->first();

        return $this->requireActiveCurrency($currency, $currencyCode);
    }

    private function lockActiveCurrency(string $currencyCode): Currency
    {
        $currency = Currency::query()
            ->where('code', $currencyCode)
            ->lockForUpdate()
            ->first();

        return $this->requireActiveCurrency($currency, $currencyCode);
    }

    private function requireActiveCurrency(?Currency $currency, string $currencyCode): Currency
    {
        if ($currency === null || ! $currency->is_active) {
            throw new InactiveCurrencyException(sprintf(
                'Currency [%s] is not an active supported currency.',
                $currencyCode,
            ));
        }

        return $currency;
    }

    private function resolveActiveCurrency(string $currencyCode): Currency
    {
        $normalizedCode = strtoupper(trim($currencyCode));

        return $this->lockActiveCurrency($normalizedCode);
    }

    private function assertOwnership(User $actor, FinancialAccount $account): void
    {
        if ((int) $actor->id !== (int) $account->user_id) {
            throw new LedgerIntegrityException('Unauthorized access to financial account.');
        }
    }
}
