<?php

namespace App\Services;

use App\DataTransferObjects\PostedJournalEntryResult;
use App\DataTransferObjects\PostingInstruction;
use App\DataTransferObjects\PostJournalEntryInput;
use App\Enums\JournalEntryStatus;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\LedgerIntegrityException;
use App\Models\Currency;
use App\Models\JournalEntry;
use App\Models\JournalPosting;
use App\Models\LedgerAccount;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class LedgerPostingService
{
    public const IDEMPOTENCY_UNIQUE_INDEX = 'journal_entries_user_id_idempotency_key_unique';

    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    /**
     * Validates and atomically records a balanced double-entry journal entry.
     *
     * @throws LedgerIntegrityException
     * @throws IdempotencyConflictException
     */
    public function post(PostJournalEntryInput $input): PostedJournalEntryResult
    {
        $currencyCode = strtoupper(trim($input->currencyCode));
        $idempotencyKey = trim($input->idempotencyKey);
        $description = trim($input->description);
        $entryDate = trim($input->entryDate);
        $externalReference = $input->externalReference !== null && trim($input->externalReference) !== ''
            ? trim($input->externalReference)
            : null;

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            throw new LedgerIntegrityException('A valid idempotency key (1 to 128 characters) is required.');
        }

        if ($description === '' || strlen($description) > 255) {
            throw new LedgerIntegrityException('A journal entry description (1 to 255 characters) is required.');
        }

        if ($externalReference !== null && strlen($externalReference) > 128) {
            throw new LedgerIntegrityException('External reference must not exceed 128 characters.');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate) !== 1) {
            throw new LedgerIntegrityException('Journal entry date must be in YYYY-MM-DD format.');
        }

        if (count($input->postings) < 2) {
            throw new LedgerIntegrityException('A posted journal entry must contain at least two postings.');
        }

        $totalDebits = 0;
        $totalCredits = 0;
        $ledgerAccountIds = [];

        foreach ($input->postings as $posting) {
            if (! $posting instanceof PostingInstruction) {
                throw new LedgerIntegrityException('Invalid posting instruction provided.');
            }

            $debit = $posting->debitAmountMinor;
            $credit = $posting->creditAmountMinor;

            if ($debit < 0 || $credit < 0) {
                throw new LedgerIntegrityException('Posting debit and credit amounts must be non-negative integers.');
            }

            if (($debit === 0 && $credit === 0) || ($debit > 0 && $credit > 0)) {
                throw new LedgerIntegrityException(
                    'Each posting must have a strictly positive integer amount on exactly one side (debit or credit).',
                );
            }

            if ($debit > Money::MAX_MINOR_AMOUNT || $credit > Money::MAX_MINOR_AMOUNT) {
                throw new LedgerIntegrityException('Posting amount exceeds safe 64-bit integer bounds.');
            }

            $totalDebits = Money::checkedAdd($totalDebits, $debit);
            $totalCredits = Money::checkedAdd($totalCredits, $credit);
            $ledgerAccountIds[] = $posting->ledgerAccountId;
        }

        if ($totalDebits <= 0) {
            throw new LedgerIntegrityException('A posted journal entry must have a strictly positive total.');
        }

        if ($totalDebits !== $totalCredits) {
            throw new LedgerIntegrityException(sprintf(
                'Unbalanced journal entry: total debits (%d) do not equal total credits (%d).',
                $totalDebits,
                $totalCredits,
            ));
        }

        $uniqueAccountIds = array_values(array_unique($ledgerAccountIds));
        sort($uniqueAccountIds);

        if (count($uniqueAccountIds) < 2) {
            throw new LedgerIntegrityException(
                'A journal entry must reference at least two distinct ledger accounts.',
            );
        }

        $requestFingerprint = $this->computeRequestFingerprint(
            userId: (int) $input->user->id,
            currencyCode: $currencyCode,
            entryDate: $entryDate,
            description: $description,
            externalReference: $externalReference,
            postings: $input->postings,
        );

        $existingEntry = JournalEntry::query()
            ->with('postings')
            ->where('user_id', $input->user->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existingEntry !== null) {
            return $this->verifyIdempotentMatch($existingEntry, $requestFingerprint);
        }

        try {
            return DB::transaction(function () use (
                $input,
                $currencyCode,
                $idempotencyKey,
                $requestFingerprint,
                $entryDate,
                $description,
                $externalReference,
                $totalDebits,
                $uniqueAccountIds
            ): PostedJournalEntryResult {
                // Re-check idempotency inside the transaction boundary
                $inTransactionExisting = JournalEntry::query()
                    ->with('postings')
                    ->where('user_id', $input->user->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($inTransactionExisting !== null) {
                    return $this->verifyIdempotentMatch($inTransactionExisting, $requestFingerprint);
                }

                // Lock and validate the currency row inside the transaction so it cannot be deactivated mid-flight
                $currency = Currency::query()
                    ->where('code', $currencyCode)
                    ->lockForUpdate()
                    ->first();

                if (! $currency || ! $currency->is_active) {
                    throw new LedgerIntegrityException(sprintf(
                        'Currency [%s] is not active or does not exist.',
                        $currencyCode,
                    ));
                }

                // Lock referenced ledger accounts in deterministic ascending ID order to prevent deadlocks
                // and ensure ownership/currency cannot mutate between validation and posting insert.
                $ledgerAccounts = LedgerAccount::query()
                    ->whereIn('id', $uniqueAccountIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if ($ledgerAccounts->count() !== count($uniqueAccountIds)) {
                    throw new LedgerIntegrityException('One or more referenced ledger accounts do not exist.');
                }

                foreach ($uniqueAccountIds as $accountId) {
                    /** @var LedgerAccount $ledgerAccount */
                    $ledgerAccount = $ledgerAccounts->get($accountId);

                    if ((int) $ledgerAccount->user_id !== (int) $input->user->id) {
                        throw new LedgerIntegrityException(
                            'All ledger accounts in a journal entry must belong to the same authenticated user.',
                        );
                    }

                    if ($ledgerAccount->currency_code !== $currencyCode) {
                        throw new LedgerIntegrityException(sprintf(
                            'Ledger account [%s] currency [%s] does not match journal entry currency [%s].',
                            $ledgerAccount->code,
                            $ledgerAccount->currency_code,
                            $currencyCode,
                        ));
                    }
                }

                $journalEntry = JournalEntry::query()->create([
                    'user_id' => $input->user->id,
                    'currency_code' => $currencyCode,
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $requestFingerprint,
                    'entry_date' => $entryDate,
                    'description' => $description,
                    'external_reference' => $externalReference,
                    'status' => JournalEntryStatus::Posted,
                    'posted_at' => now(),
                ]);

                foreach ($input->postings as $posting) {
                    JournalPosting::query()->create([
                        'journal_entry_id' => $journalEntry->id,
                        'ledger_account_id' => $posting->ledgerAccountId,
                        'debit_amount_minor' => $posting->debitAmountMinor,
                        'credit_amount_minor' => $posting->creditAmountMinor,
                    ]);
                }

                $this->auditService->record(
                    actor: $input->user,
                    action: AuditService::ACTION_JOURNAL_ENTRY_POSTED,
                    auditableType: JournalEntry::class,
                    auditableId: $journalEntry->id,
                    metadata: [
                        'currency_code' => $currencyCode,
                        'entry_date' => $entryDate,
                        'total_amount_minor' => $totalDebits,
                        'posting_count' => count($input->postings),
                        'idempotency_key' => $idempotencyKey,
                    ],
                );

                return new PostedJournalEntryResult(
                    journalEntry: $journalEntry->load('postings'),
                    wasRecentlyCreated: true,
                );
            });
        } catch (UniqueConstraintViolationException $exception) {
            if ($this->isJournalEntryIdempotencyConstraintViolation($exception)) {
                return $this->resolveConcurrentEntry(
                    userId: (int) $input->user->id,
                    idempotencyKey: $idempotencyKey,
                    requestFingerprint: $requestFingerprint,
                    originalException: $exception,
                );
            }

            throw $exception;
        } catch (QueryException $exception) {
            if ($this->isJournalEntryIdempotencyConstraintViolation($exception)) {
                return $this->resolveConcurrentEntry(
                    userId: (int) $input->user->id,
                    idempotencyKey: $idempotencyKey,
                    requestFingerprint: $requestFingerprint,
                    originalException: $exception,
                );
            }

            throw $exception;
        }
    }

    /**
     * Computes a deterministic SHA-256 fingerprint of the normalized financial payload.
     *
     * Posting lines are canonicalized by (ledger_account_id, debit_amount_minor, credit_amount_minor)
     * so that equivalent double-entry sets produce an identical fingerprint regardless of line order.
     *
     * @param  list<PostingInstruction>  $postings
     */
    public function computeRequestFingerprint(
        int $userId,
        string $currencyCode,
        string $entryDate,
        string $description,
        ?string $externalReference,
        array $postings,
    ): string {
        $normalizedPostings = array_map(
            static fn (PostingInstruction $posting): array => [
                'ledger_account_id' => $posting->ledgerAccountId,
                'debit_amount_minor' => $posting->debitAmountMinor,
                'credit_amount_minor' => $posting->creditAmountMinor,
            ],
            $postings,
        );

        usort($normalizedPostings, static function (array $left, array $right): int {
            return [$left['ledger_account_id'], $left['debit_amount_minor'], $left['credit_amount_minor']]
                <=> [$right['ledger_account_id'], $right['debit_amount_minor'], $right['credit_amount_minor']];
        });

        $canonicalPayload = [
            'user_id' => $userId,
            'currency_code' => $currencyCode,
            'entry_date' => $entryDate,
            'description' => $description,
            'external_reference' => $externalReference,
            'postings' => $normalizedPostings,
        ];

        return hash('sha256', (string) json_encode($canonicalPayload, JSON_THROW_ON_ERROR));
    }

    private function verifyIdempotentMatch(
        JournalEntry $existingEntry,
        string $requestFingerprint,
    ): PostedJournalEntryResult {
        if (! hash_equals((string) $existingEntry->request_fingerprint, $requestFingerprint)) {
            throw new IdempotencyConflictException(
                'Idempotency key has already been used with a different journal entry payload.',
            );
        }

        return new PostedJournalEntryResult(
            journalEntry: $existingEntry,
            wasRecentlyCreated: false,
        );
    }

    private function resolveConcurrentEntry(
        int $userId,
        string $idempotencyKey,
        string $requestFingerprint,
        QueryException $originalException,
    ): PostedJournalEntryResult {
        $existingEntry = JournalEntry::query()
            ->with('postings')
            ->where('user_id', $userId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existingEntry !== null) {
            return $this->verifyIdempotentMatch($existingEntry, $requestFingerprint);
        }

        throw $originalException;
    }

    /**
     * Determines whether a QueryException specifically represents a violation of the
     * journal_entries(user_id, idempotency_key) unique constraint rather than an unrelated unique index.
     */
    private function isJournalEntryIdempotencyConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = strtolower($exception->getMessage());

        $isUniqueError = $sqlState === '23505'
            || $driverCode === 1062
            || $driverCode === 2067
            || (in_array($sqlState, ['23000', '19'], true) && str_contains($message, 'unique'));

        if (! $isUniqueError) {
            return false;
        }

        // Verify the violation specifically references the journal_entries idempotency index or columns
        return str_contains($message, self::IDEMPOTENCY_UNIQUE_INDEX)
            || (
                str_contains($message, 'journal_entries.user_id')
                && str_contains($message, 'journal_entries.idempotency_key')
            );
    }
}
