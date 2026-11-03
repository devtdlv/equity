<?php

namespace App\Services;

use App\DataTransferObjects\PostedOpeningBalanceResult;
use App\DataTransferObjects\PostingInstruction;
use App\DataTransferObjects\PostJournalEntryInput;
use App\DataTransferObjects\PostOpeningBalanceInput;
use App\Enums\LedgerAccountClassification;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\LedgerIntegrityException;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\Money;
use App\Support\TransientTransactionFailure;
use App\Support\TransientTransactionRetryBackoff;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Posts a one-time opening-balance journal for an existing financial account.
 *
 * Does not create financial_transactions. Not wired to account creation or HTTP routes.
 *
 * Lock order: financial_accounts, then currencies, then ledger_accounts (equity
 * provisioning and LedgerPostingService, the latter by ascending ledger id).
 * The account ledger is never locked before the currency.
 *
 * Replay of a pointed journal does not call LedgerPostingService and does not
 * provision opening equity. The pointed journal is loaded and checked first; a
 * different idempotency key is rejected without posting. The same key locates
 * the existing EQ-OPEN-{CCY} ledger account with a read-only query, then
 * compares computeRequestFingerprint().
 */
class OpeningBalanceService
{
    public const CANONICAL_DESCRIPTION = 'Starting balance';

    public const MAX_CONCURRENCY_ATTEMPTS = 3;

    public function __construct(
        private readonly LedgerPostingService $ledgerPostingService,
        private readonly OpeningEquityAccountService $openingEquityAccountService,
        private readonly AuditService $auditService,
    ) {}

    public function postForAccount(PostOpeningBalanceInput $input): PostedOpeningBalanceResult
    {
        $this->assertValidUser($input->user);
        $this->assertFinancialAccountId($input->financialAccountId);
        $this->assertAmountMinor($input->amountMinor);
        $entryDate = $this->validateEntryDate($input->entryDate, rejectFuture: true);
        $idempotencyKey = $this->validateIdempotencyKey($input->idempotencyKey);

        if (DB::transactionLevel() > 0) {
            return $this->postForAccountInCurrentTransaction(
                user: $input->user,
                financialAccountId: $input->financialAccountId,
                amountMinor: $input->amountMinor,
                entryDate: $entryDate,
                idempotencyKey: $idempotencyKey,
            );
        }

        for ($attempt = 1; $attempt <= self::MAX_CONCURRENCY_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($input, $entryDate, $idempotencyKey): PostedOpeningBalanceResult {
                    return $this->postForAccountInCurrentTransaction(
                        user: $input->user,
                        financialAccountId: $input->financialAccountId,
                        amountMinor: $input->amountMinor,
                        entryDate: $entryDate,
                        idempotencyKey: $idempotencyKey,
                    );
                });
            } catch (QueryException $exception) {
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
            'Unable to record the opening balance due to concurrent contention.',
        );
    }

    private function postForAccountInCurrentTransaction(
        User $user,
        int $financialAccountId,
        int $amountMinor,
        string $entryDate,
        string $idempotencyKey,
    ): PostedOpeningBalanceResult {
        /** @var FinancialAccount|null $lockedAccount */
        $lockedAccount = FinancialAccount::query()
            ->whereKey($financialAccountId)
            ->lockForUpdate()
            ->first();

        if ($lockedAccount === null || (int) $lockedAccount->user_id !== (int) $user->id) {
            throw new LedgerIntegrityException(
                'Selected financial account does not exist or is not accessible.',
            );
        }

        $accountLedger = $this->linkedAccountLedger($lockedAccount);
        $this->assertAccountLedgerMatchesAccount($lockedAccount, $accountLedger);
        $this->assertSupportedOpeningClassification($accountLedger);

        if ($lockedAccount->opening_journal_entry_id !== null) {
            return $this->replayPointedOpening(
                user: $user,
                lockedAccount: $lockedAccount,
                accountLedger: $accountLedger,
                amountMinor: $amountMinor,
                entryDate: $entryDate,
                idempotencyKey: $idempotencyKey,
            );
        }

        if ($lockedAccount->is_archived) {
            throw new LedgerIntegrityException(
                'Archived financial accounts cannot receive a new opening balance.',
            );
        }

        if ($amountMinor === 0) {
            return new PostedOpeningBalanceResult(
                account: $lockedAccount->load(['currency', 'ledgerAccount']),
                amountMinor: 0,
                wasRecentlyCreated: false,
                isNoOp: true,
                journalEntry: null,
            );
        }

        if ($accountLedger->postings()->exists()) {
            throw new LedgerIntegrityException(
                'An opening balance cannot be recorded after journal activity exists for this account.',
            );
        }

        $currency = $this->lockCurrency($lockedAccount->currency_code, requireActive: true);
        $equityLedger = $this->openingEquityAccountService->resolveOrCreate($user, $currency);
        $postings = $this->buildPostingInstructions($accountLedger, $equityLedger, $amountMinor);
        $externalReference = $this->externalReference((int) $lockedAccount->id);

        $ledgerResult = $this->ledgerPostingService->post(new PostJournalEntryInput(
            user: $user,
            currencyCode: $currency->code,
            entryDate: $entryDate,
            description: self::CANONICAL_DESCRIPTION,
            idempotencyKey: $idempotencyKey,
            postings: $postings,
            externalReference: $externalReference,
        ));

        if (! $ledgerResult->wasRecentlyCreated) {
            throw new LedgerIntegrityException(
                'Idempotency key has already been used by a journal entry that is not this account\'s opening balance.',
            );
        }

        $lockedAccount->opening_journal_entry_id = $ledgerResult->journalEntry->id;
        $lockedAccount->save();

        $this->auditService->record(
            actor: $user,
            action: AuditService::ACTION_ACCOUNT_OPENING_POSTED,
            auditableType: FinancialAccount::class,
            auditableId: (int) $lockedAccount->id,
            metadata: [
                'journal_entry_id' => (int) $ledgerResult->journalEntry->id,
                'amount_minor' => $amountMinor,
                'currency_code' => $currency->code,
                'entry_date' => $entryDate,
                'classification' => $accountLedger->classification instanceof LedgerAccountClassification
                    ? $accountLedger->classification->value
                    : (string) $accountLedger->classification,
                'idempotency_key' => $idempotencyKey,
            ],
        );

        return new PostedOpeningBalanceResult(
            account: $lockedAccount->load(['currency', 'ledgerAccount']),
            amountMinor: $amountMinor,
            wasRecentlyCreated: true,
            isNoOp: false,
            journalEntry: $ledgerResult->journalEntry,
        );
    }

    private function replayPointedOpening(
        User $user,
        FinancialAccount $lockedAccount,
        LedgerAccount $accountLedger,
        int $amountMinor,
        string $entryDate,
        string $idempotencyKey,
    ): PostedOpeningBalanceResult {
        /** @var JournalEntry|null $pointedJournal */
        $pointedJournal = JournalEntry::query()
            ->with('postings')
            ->whereKey((int) $lockedAccount->opening_journal_entry_id)
            ->lockForUpdate()
            ->first();

        if ($pointedJournal === null) {
            throw new LedgerIntegrityException(
                'The opening balance journal pointed to by this account does not exist.',
            );
        }

        if ((int) $pointedJournal->user_id !== (int) $user->id) {
            throw new LedgerIntegrityException(
                'The opening balance journal does not belong to this user.',
            );
        }

        if ($pointedJournal->currency_code !== $lockedAccount->currency_code) {
            throw new LedgerIntegrityException(
                'The opening balance journal currency does not match this account.',
            );
        }

        if (! hash_equals((string) $pointedJournal->idempotency_key, $idempotencyKey)) {
            throw new LedgerIntegrityException(
                'An opening balance journal has already been recorded for this account.',
            );
        }

        if ($amountMinor === 0) {
            throw new IdempotencyConflictException(
                'Idempotency key has already been used with a different journal entry payload.',
            );
        }

        $currency = $this->lockCurrency($lockedAccount->currency_code, requireActive: false);
        $equityLedger = $this->existingOpeningEquityLedger($user, $currency);
        $postings = $this->buildPostingInstructions($accountLedger, $equityLedger, $amountMinor);
        $requestFingerprint = $this->ledgerPostingService->computeRequestFingerprint(
            userId: (int) $user->id,
            currencyCode: $currency->code,
            entryDate: $entryDate,
            description: self::CANONICAL_DESCRIPTION,
            externalReference: $this->externalReference((int) $lockedAccount->id),
            postings: $postings,
        );

        if (! hash_equals((string) $pointedJournal->request_fingerprint, $requestFingerprint)) {
            throw new IdempotencyConflictException(
                'Idempotency key has already been used with a different journal entry payload.',
            );
        }

        return new PostedOpeningBalanceResult(
            account: $lockedAccount->load(['currency', 'ledgerAccount']),
            amountMinor: $amountMinor,
            wasRecentlyCreated: false,
            isNoOp: false,
            journalEntry: $pointedJournal,
        );
    }

    /**
     * @return list<PostingInstruction>
     */
    private function buildPostingInstructions(
        LedgerAccount $accountLedger,
        LedgerAccount $equityLedger,
        int $amountMinor,
    ): array {
        $classification = $this->assertSupportedOpeningClassification($accountLedger);

        $magnitude = $this->positiveMagnitude($amountMinor);
        $accountId = (int) $accountLedger->id;
        $equityId = (int) $equityLedger->id;

        $debitAccount = false;

        if ($classification === LedgerAccountClassification::Asset) {
            $debitAccount = $amountMinor > 0;
        } else {
            $debitAccount = $amountMinor < 0;
        }

        if ($debitAccount) {
            return [
                new PostingInstruction(
                    ledgerAccountId: $accountId,
                    debitAmountMinor: $magnitude,
                    creditAmountMinor: 0,
                ),
                new PostingInstruction(
                    ledgerAccountId: $equityId,
                    debitAmountMinor: 0,
                    creditAmountMinor: $magnitude,
                ),
            ];
        }

        return [
            new PostingInstruction(
                ledgerAccountId: $equityId,
                debitAmountMinor: $magnitude,
                creditAmountMinor: 0,
            ),
            new PostingInstruction(
                ledgerAccountId: $accountId,
                debitAmountMinor: 0,
                creditAmountMinor: $magnitude,
            ),
        ];
    }

    private function existingOpeningEquityLedger(User $user, Currency $currency): LedgerAccount
    {
        $currencyCode = (string) $currency->code;
        $code = OpeningEquityAccountService::canonicalCode($currencyCode);

        $existing = LedgerAccount::query()
            ->where('user_id', $user->id)
            ->where('code', $code)
            ->first();

        if ($existing === null) {
            throw new LedgerIntegrityException(sprintf(
                'Opening Balances Equity ledger account [%s] does not exist for this user.',
                $code,
            ));
        }

        $this->assertExistingOpeningEquity($existing, $user, $currencyCode, $code);

        return $existing;
    }

    private function assertExistingOpeningEquity(
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

    private function linkedAccountLedger(FinancialAccount $account): LedgerAccount
    {
        $ledger = LedgerAccount::query()
            ->where('financial_account_id', $account->id)
            ->first();

        if ($ledger === null) {
            throw new LedgerIntegrityException(
                'Selected financial account is missing its linked ledger account.',
            );
        }

        return $ledger;
    }

    private function assertAccountLedgerMatchesAccount(FinancialAccount $account, LedgerAccount $ledger): void
    {
        if ((int) $ledger->user_id !== (int) $account->user_id) {
            throw new LedgerIntegrityException(
                'Linked ledger account owner does not match the financial account.',
            );
        }

        if ($ledger->currency_code !== $account->currency_code) {
            throw new LedgerIntegrityException(
                'Linked ledger account currency does not match the financial account.',
            );
        }

        $expected = $account->type->ledgerClassification();
        $actual = $ledger->classification instanceof LedgerAccountClassification
            ? $ledger->classification
            : LedgerAccountClassification::tryFrom((string) $ledger->classification);

        if ($actual !== $expected) {
            throw new LedgerIntegrityException(
                'Linked ledger account classification does not match the financial account type.',
            );
        }
    }

    private function assertSupportedOpeningClassification(LedgerAccount $ledger): LedgerAccountClassification
    {
        $classification = $ledger->classification instanceof LedgerAccountClassification
            ? $ledger->classification
            : LedgerAccountClassification::tryFrom((string) $ledger->classification);

        if (
            $classification !== LedgerAccountClassification::Asset
            && $classification !== LedgerAccountClassification::Liability
        ) {
            throw new LedgerIntegrityException(
                'Opening balances are only supported for asset and liability financial accounts.',
            );
        }

        return $classification;
    }

    private function lockCurrency(string $currencyCode, bool $requireActive): Currency
    {
        if (strlen($currencyCode) !== 3 || ! ctype_alpha($currencyCode) || ! ctype_upper($currencyCode)) {
            throw new LedgerIntegrityException(
                'Currency code must be a canonical 3-letter ISO 4217 code.',
            );
        }

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

        if ($requireActive && ! $lockedCurrency->is_active) {
            throw new LedgerIntegrityException(sprintf(
                'Currency [%s] is not an active supported currency.',
                $lockedCurrency->code,
            ));
        }

        return $lockedCurrency;
    }

    private function externalReference(int $financialAccountId): string
    {
        return 'OPEN:'.$financialAccountId;
    }

    private function positiveMagnitude(int $amountMinor): int
    {
        if ($amountMinor > 0) {
            return $amountMinor;
        }

        if ($amountMinor < 0) {
            return -$amountMinor;
        }

        throw new LedgerIntegrityException('A non-zero opening amount is required to build journal postings.');
    }

    private function assertAmountMinor(int $amountMinor): void
    {
        if ($amountMinor > Money::MAX_MINOR_AMOUNT || $amountMinor < -Money::MAX_MINOR_AMOUNT) {
            throw new LedgerIntegrityException('Monetary amount exceeds safe 64-bit integer bounds.');
        }
    }

    private function validateEntryDate(string $entryDate, bool $rejectFuture): string
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $entryDate) !== 1) {
            throw new LedgerIntegrityException('Opening balance date must be a valid YYYY-MM-DD date.');
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $entryDate);
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $parsed === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $entryDate
        ) {
            throw new LedgerIntegrityException('Opening balance date must be a valid calendar date.');
        }

        if ($rejectFuture) {
            $todayUtc = now('UTC')->format('Y-m-d');

            if ($entryDate > $todayUtc) {
                throw new LedgerIntegrityException('Opening balance date cannot be in the future.');
            }
        }

        return $entryDate;
    }

    private function validateIdempotencyKey(string $idempotencyKey): string
    {
        $trimmed = trim($idempotencyKey);

        if ($trimmed === '' || strlen($trimmed) > 128) {
            throw new LedgerIntegrityException('A valid idempotency key (1 to 128 characters) is required.');
        }

        return $trimmed;
    }

    private function assertFinancialAccountId(int $financialAccountId): void
    {
        if ($financialAccountId <= 0) {
            throw new LedgerIntegrityException('Financial account identifier must be a positive integer.');
        }
    }

    private function assertValidUser(User $user): void
    {
        if (! $user->exists || $user->id === null) {
            throw new LedgerIntegrityException('Authenticated user is required.');
        }
    }
}
