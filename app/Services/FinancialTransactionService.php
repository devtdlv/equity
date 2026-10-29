<?php

namespace App\Services;

use App\DataTransferObjects\CreatedFinancialTransactionResult;
use App\DataTransferObjects\CreateFinancialTransactionInput;
use App\DataTransferObjects\PostingInstruction;
use App\DataTransferObjects\PostJournalEntryInput;
use App\DataTransferObjects\ReversedFinancialTransactionResult;
use App\DataTransferObjects\ReverseFinancialTransactionInput;
use App\Enums\FinancialTransactionType;
use App\Enums\JournalEntryStatus;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\LedgerIntegrityException;
use App\Models\Category;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\Money;
use App\Support\MoneyInputParser;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ValueError;

class FinancialTransactionService
{
    public const CATEGORY_LEDGER_UNIQUE_INDEX = 'ledger_accounts_category_id_currency_code_unique';

    public const REVERSED_TRANSACTION_UNIQUE_INDEX = 'financial_transactions_reversed_transaction_id_unique';

    public const MAX_NOTES_LENGTH = 2000;

    public const MAX_REVERSAL_REASON_LENGTH = 255;

    public const MAX_CONCURRENCY_ATTEMPTS = 3;

    public function __construct(
        private readonly LedgerPostingService $ledgerPostingService,
        private readonly AuditService $auditService,
    ) {}

    /**
     * Validates and atomically records an income, expense, or transfer transaction
     * alongside its balanced double-entry journal entry, postings, and audit trail.
     *
     * Idempotency keys for user-facing transactions must be valid UUIDs and are normalized
     * to lowercase before storage and comparison.
     *
     * @throws LedgerIntegrityException
     * @throws IdempotencyConflictException
     */
    public function createTransaction(CreateFinancialTransactionInput $input): CreatedFinancialTransactionResult
    {
        $this->assertValidUser($input->user);

        $transactionType = $this->resolveTransactionType($input->type);
        $idempotencyKey = $this->validateUuidIdempotencyKey($input->idempotencyKey);
        $entryDate = $this->validateEntryDate($input->entryDate);
        $description = $this->validateDescription($input->description);
        $notes = $this->normalizeAndValidateNotes($input->notes);

        $this->validateTypeSpecificInputs(
            type: $transactionType,
            financialAccountId: $input->financialAccountId,
            transferFinancialAccountId: $input->transferFinancialAccountId,
            categoryId: $input->categoryId,
        );

        for ($attempt = 1; $attempt <= self::MAX_CONCURRENCY_ATTEMPTS; $attempt++) {
            // Check for an existing idempotent journal entry in a fresh snapshot outside the write transaction
            $existingJournal = JournalEntry::query()
                ->with([
                    'postings',
                    'financialTransaction.financialAccount.ledgerAccount',
                    'financialTransaction.transferFinancialAccount.ledgerAccount',
                    'financialTransaction.category',
                ])
                ->where('user_id', $input->user->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingJournal !== null) {
                return $this->verifyExistingTransactionMatch(
                    existingJournal: $existingJournal,
                    input: $input,
                    transactionType: $transactionType,
                    entryDate: $entryDate,
                    description: $description,
                    notes: $notes,
                );
            }

            try {
                return DB::transaction(function () use (
                    $input,
                    $transactionType,
                    $idempotencyKey,
                    $entryDate,
                    $description,
                    $notes
                ): CreatedFinancialTransactionResult {
                    // 1. Re-check idempotency inside the transaction boundary with row locking
                    $inTransactionJournal = JournalEntry::query()
                        ->with([
                            'postings',
                            'financialTransaction.financialAccount.ledgerAccount',
                            'financialTransaction.transferFinancialAccount.ledgerAccount',
                            'financialTransaction.category',
                        ])
                        ->where('user_id', $input->user->id)
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($inTransactionJournal !== null) {
                        return $this->verifyExistingTransactionMatch(
                            existingJournal: $inTransactionJournal,
                            input: $input,
                            transactionType: $transactionType,
                            entryDate: $entryDate,
                            description: $description,
                            notes: $notes,
                        );
                    }

                    // 2. Lock financial account(s) in ascending ID order to prevent deadlocks
                    $accountIdsToLock = [$input->financialAccountId];
                    if ($transactionType === FinancialTransactionType::Transfer && $input->transferFinancialAccountId !== null) {
                        $accountIdsToLock[] = $input->transferFinancialAccountId;
                        sort($accountIdsToLock);
                    }

                    $lockedAccounts = FinancialAccount::query()
                        ->with('ledgerAccount')
                        ->whereIn('id', $accountIdsToLock)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');

                    /** @var FinancialAccount|null $primaryAccount */
                    $primaryAccount = $lockedAccounts->get($input->financialAccountId);

                    if ($primaryAccount === null || (int) $primaryAccount->user_id !== (int) $input->user->id) {
                        throw new LedgerIntegrityException('Selected financial account does not exist or is not accessible.');
                    }

                    if ($primaryAccount->is_archived) {
                        throw new LedgerIntegrityException('Archived financial accounts cannot be used for new transactions.');
                    }

                    $primaryLedgerAccount = $primaryAccount->ledgerAccount;
                    if ($primaryLedgerAccount === null) {
                        throw new LedgerIntegrityException('Selected financial account is missing its linked ledger account.');
                    }

                    $currencyCode = $primaryAccount->currency_code;

                    // 3. Lock category (if income/expense) BEFORE locking currency or ledger accounts
                    // to maintain a strict global lock hierarchy (financial_accounts -> categories -> currencies -> ledger_accounts)
                    $category = null;
                    $categoryId = null;

                    if ($transactionType !== FinancialTransactionType::Transfer) {
                        /** @var Category|null $category */
                        $category = Category::query()
                            ->whereKey((int) $input->categoryId)
                            ->lockForUpdate()
                            ->first();

                        if ($category === null || (int) $category->user_id !== (int) $input->user->id) {
                            throw new LedgerIntegrityException('Selected category does not exist or is not accessible.');
                        }

                        if ($category->is_archived) {
                            throw new LedgerIntegrityException('Archived categories cannot be used for new transactions.');
                        }

                        if ($category->type->value !== $transactionType->value) {
                            throw new LedgerIntegrityException(sprintf(
                                'Cannot use an [%s] category for a [%s] transaction.',
                                $category->type->value,
                                $transactionType->value,
                            ));
                        }

                        $categoryId = (int) $category->id;
                    }

                    // 4. Lock and validate the currency row
                    /** @var Currency|null $currency */
                    $currency = Currency::query()
                        ->where('code', $currencyCode)
                        ->lockForUpdate()
                        ->first();

                    if ($currency === null || ! $currency->is_active) {
                        throw new LedgerIntegrityException(sprintf(
                            'Currency [%s] is not active or does not exist.',
                            $currencyCode,
                        ));
                    }

                    $amountMinor = MoneyInputParser::parseToMinorUnits(
                        $input->amount,
                        $currency->minor_unit_exponent,
                    );

                    $postings = [];
                    $transferAccountId = null;

                    if ($transactionType === FinancialTransactionType::Transfer) {
                        /** @var FinancialAccount|null $destinationAccount */
                        $destinationAccount = $lockedAccounts->get((int) $input->transferFinancialAccountId);

                        if ($destinationAccount === null || (int) $destinationAccount->user_id !== (int) $input->user->id) {
                            throw new LedgerIntegrityException(
                                'Transfer destination account does not exist or is not accessible.',
                            );
                        }

                        if ($destinationAccount->is_archived) {
                            throw new LedgerIntegrityException(
                                'Archived financial accounts cannot be used as a transfer destination.',
                            );
                        }

                        if ($destinationAccount->currency_code !== $currencyCode) {
                            throw new LedgerIntegrityException(
                                'Cross-currency transfers are not supported yet. Both accounts must share the same currency.',
                            );
                        }

                        $destinationLedgerAccount = $destinationAccount->ledgerAccount;
                        if ($destinationLedgerAccount === null) {
                            throw new LedgerIntegrityException(
                                'Transfer destination account is missing its linked ledger account.',
                            );
                        }

                        $transferAccountId = (int) $destinationAccount->id;

                        // Transfer: Debit destination financial account's ledger account, Credit source financial account's ledger account
                        $postings = [
                            new PostingInstruction(
                                ledgerAccountId: (int) $destinationLedgerAccount->id,
                                debitAmountMinor: $amountMinor,
                                creditAmountMinor: 0,
                            ),
                            new PostingInstruction(
                                ledgerAccountId: (int) $primaryLedgerAccount->id,
                                debitAmountMinor: 0,
                                creditAmountMinor: $amountMinor,
                            ),
                        ];
                    } else {
                        /** @var Category $category */
                        $categoryLedgerAccount = $this->resolveOrCreateCategoryLedgerAccountInTransaction(
                            user: $input->user,
                            lockedCategory: $category,
                            currencyCode: $currencyCode,
                        );

                        if ($transactionType === FinancialTransactionType::Income) {
                            // Income: Debit destination financial account's ledger account, Credit income category ledger account
                            $postings = [
                                new PostingInstruction(
                                    ledgerAccountId: (int) $primaryLedgerAccount->id,
                                    debitAmountMinor: $amountMinor,
                                    creditAmountMinor: 0,
                                ),
                                new PostingInstruction(
                                    ledgerAccountId: (int) $categoryLedgerAccount->id,
                                    debitAmountMinor: 0,
                                    creditAmountMinor: $amountMinor,
                                ),
                            ];
                        } else {
                            // Expense: Debit expense category ledger account, Credit source financial account's ledger account
                            $postings = [
                                new PostingInstruction(
                                    ledgerAccountId: (int) $categoryLedgerAccount->id,
                                    debitAmountMinor: $amountMinor,
                                    creditAmountMinor: 0,
                                ),
                                new PostingInstruction(
                                    ledgerAccountId: (int) $primaryLedgerAccount->id,
                                    debitAmountMinor: 0,
                                    creditAmountMinor: $amountMinor,
                                ),
                            ];
                        }
                    }

                    $canonicalReference = $this->computeCanonicalTransactionReference(
                        userId: (int) $input->user->id,
                        type: $transactionType,
                        financialAccountId: (int) $primaryAccount->id,
                        transferFinancialAccountId: $transferAccountId,
                        categoryId: $categoryId,
                        currencyCode: $currencyCode,
                        amountMinor: $amountMinor,
                        entryDate: $entryDate,
                        description: $description,
                        notes: $notes,
                    );

                    $ledgerResult = $this->ledgerPostingService->post(new PostJournalEntryInput(
                        user: $input->user,
                        currencyCode: $currencyCode,
                        entryDate: $entryDate,
                        description: $description,
                        idempotencyKey: $idempotencyKey,
                        postings: $postings,
                        externalReference: $canonicalReference,
                    ));

                    $journalEntry = $ledgerResult->journalEntry;

                    if (! $ledgerResult->wasRecentlyCreated) {
                        $existingJournalWithTx = JournalEntry::query()
                            ->with([
                                'postings',
                                'financialTransaction.financialAccount.ledgerAccount',
                                'financialTransaction.transferFinancialAccount.ledgerAccount',
                                'financialTransaction.category',
                            ])
                            ->whereKey($journalEntry->id)
                            ->firstOrFail();

                        return $this->verifyExistingTransactionMatch(
                            existingJournal: $existingJournalWithTx,
                            input: $input,
                            transactionType: $transactionType,
                            entryDate: $entryDate,
                            description: $description,
                            notes: $notes,
                        );
                    }

                    $transaction = FinancialTransaction::query()->create([
                        'user_id' => $input->user->id,
                        'journal_entry_id' => $journalEntry->id,
                        'financial_account_id' => $primaryAccount->id,
                        'transfer_financial_account_id' => $transferAccountId,
                        'category_id' => $categoryId,
                        'type' => $transactionType,
                        'currency_code' => $currencyCode,
                        'amount_minor' => $amountMinor,
                        'entry_date' => $entryDate,
                        'description' => $description,
                        'notes' => $notes,
                    ]);

                    $this->auditService->record(
                        actor: $input->user,
                        action: AuditService::ACTION_TRANSACTION_RECORDED,
                        auditableType: FinancialTransaction::class,
                        auditableId: $transaction->id,
                        metadata: [
                            'journal_entry_id' => $journalEntry->id,
                            'type' => $transactionType->value,
                            'currency_code' => $currencyCode,
                            'amount_minor' => $amountMinor,
                            'entry_date' => $entryDate,
                            'financial_account_id' => $primaryAccount->id,
                            'transfer_financial_account_id' => $transferAccountId,
                            'category_id' => $categoryId,
                        ],
                    );

                    return new CreatedFinancialTransactionResult(
                        transaction: $transaction->load(['financialAccount', 'transferFinancialAccount', 'category', 'journalEntry.postings']),
                        journalEntry: $journalEntry,
                        wasRecentlyCreated: true,
                    );
                });
            } catch (UniqueConstraintViolationException|QueryException $exception) {
                if (
                    $attempt < self::MAX_CONCURRENCY_ATTEMPTS
                    && $this->isRetryableConcurrencyException($exception)
                ) {
                    continue;
                }

                if ($this->isJournalEntryIdempotencyConstraintViolation($exception)) {
                    $recoveredJournal = JournalEntry::query()
                        ->with([
                            'postings',
                            'financialTransaction.financialAccount.ledgerAccount',
                            'financialTransaction.transferFinancialAccount.ledgerAccount',
                            'financialTransaction.category',
                        ])
                        ->where('user_id', $input->user->id)
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();

                    if ($recoveredJournal !== null) {
                        return $this->verifyExistingTransactionMatch(
                            existingJournal: $recoveredJournal,
                            input: $input,
                            transactionType: $transactionType,
                            entryDate: $entryDate,
                            description: $description,
                            notes: $notes,
                        );
                    }
                }

                throw $exception;
            }
        }

        throw new LedgerIntegrityException('Unable to complete transaction due to concurrent database contention.');
    }

    /**
     * Finds or creates the unique category-backed LedgerAccount for a (category_id, currency_code) pair.
     *
     * Locks the parent Category row inside a transaction to serialize first-time creation for that
     * category. If a concurrent unique-constraint collision or deadlock occurs, the transaction rolls
     * back cleanly before retrying and revalidating ownership, archive state, and classification.
     */
    public function resolveOrCreateCategoryLedgerAccount(
        User $user,
        Category $category,
        string $currencyCode,
    ): LedgerAccount {
        $this->assertValidUser($user);
        $normalizedCurrency = strtoupper(trim($currencyCode));

        for ($attempt = 1; $attempt <= self::MAX_CONCURRENCY_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($user, $category, $normalizedCurrency): LedgerAccount {
                    /** @var Category|null $lockedCategory */
                    $lockedCategory = Category::query()
                        ->whereKey($category->id)
                        ->lockForUpdate()
                        ->first();

                    if ($lockedCategory === null || (int) $lockedCategory->user_id !== (int) $user->id) {
                        throw new LedgerIntegrityException('Selected category does not exist or is not accessible.');
                    }

                    if ($lockedCategory->is_archived) {
                        throw new LedgerIntegrityException('Archived categories cannot be used for new transactions.');
                    }

                    /** @var Currency|null $lockedCurrency */
                    $lockedCurrency = Currency::query()
                        ->where('code', $normalizedCurrency)
                        ->lockForUpdate()
                        ->first();

                    if ($lockedCurrency === null || ! $lockedCurrency->is_active) {
                        throw new LedgerIntegrityException(sprintf(
                            'Currency [%s] is not active or does not exist.',
                            $normalizedCurrency,
                        ));
                    }

                    return $this->resolveOrCreateCategoryLedgerAccountInTransaction(
                        user: $user,
                        lockedCategory: $lockedCategory,
                        currencyCode: $normalizedCurrency,
                    );
                });
            } catch (UniqueConstraintViolationException|QueryException $exception) {
                if (
                    $attempt < self::MAX_CONCURRENCY_ATTEMPTS
                    && (
                        $this->isCategoryLedgerUniqueViolation($exception)
                        || $this->isDeadlockOrSerializationFailure($exception)
                    )
                ) {
                    continue;
                }

                throw $exception;
            }
        }

        throw new LedgerIntegrityException('Unable to resolve category ledger account due to concurrent contention.');
    }

    /**
     * Computes a deterministic external_reference digest representing the full canonical meaning
     * of a financial transaction (including notes, transaction type, accounts, and category).
     */
    public function computeCanonicalTransactionReference(
        int $userId,
        FinancialTransactionType $type,
        int $financialAccountId,
        ?int $transferFinancialAccountId,
        ?int $categoryId,
        string $currencyCode,
        int $amountMinor,
        string $entryDate,
        string $description,
        ?string $notes,
    ): string {
        $payload = [
            'user_id' => $userId,
            'type' => $type->value,
            'financial_account_id' => $financialAccountId,
            'transfer_financial_account_id' => $transferFinancialAccountId,
            'category_id' => $categoryId,
            'currency_code' => $currencyCode,
            'amount_minor' => $amountMinor,
            'entry_date' => $entryDate,
            'description' => $description,
            'notes' => $notes,
        ];

        return 'TX:'.hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Atomically reverses a posted financial transaction by posting a new balanced journal entry
     * with exact debit/credit opposites and recording a linked reversal FinancialTransaction.
     *
     * Posted journal entries and financial transactions are never updated or deleted.
     *
     * @throws LedgerIntegrityException
     * @throws IdempotencyConflictException
     */
    public function reverseTransaction(ReverseFinancialTransactionInput $input): ReversedFinancialTransactionResult
    {
        $this->assertValidUser($input->user);
        $idempotencyKey = $this->validateUuidIdempotencyKey($input->idempotencyKey);
        $reason = $this->normalizeAndValidateReversalReason($input->reason);

        if ($input->originalTransactionId <= 0) {
            throw new LedgerIntegrityException(
                'The transaction to reverse does not exist or is not accessible.',
            );
        }

        for ($attempt = 1; $attempt <= self::MAX_CONCURRENCY_ATTEMPTS; $attempt++) {
            $existingJournal = JournalEntry::query()
                ->with([
                    'postings',
                    'financialTransaction.reversedTransaction.journalEntry.postings',
                    'financialTransaction.financialAccount',
                    'financialTransaction.transferFinancialAccount',
                    'financialTransaction.category',
                ])
                ->where('user_id', $input->user->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingJournal !== null) {
                return $this->verifyExistingReversalMatch(
                    existingJournal: $existingJournal,
                    input: $input,
                    reason: $reason,
                );
            }

            try {
                return DB::transaction(function () use (
                    $input,
                    $idempotencyKey,
                    $reason
                ): ReversedFinancialTransactionResult {
                    // 1. Re-check idempotency inside the transaction boundary with row locking
                    $inTransactionJournal = JournalEntry::query()
                        ->with([
                            'postings',
                            'financialTransaction.reversedTransaction.journalEntry.postings',
                            'financialTransaction.financialAccount',
                            'financialTransaction.transferFinancialAccount',
                            'financialTransaction.category',
                        ])
                        ->where('user_id', $input->user->id)
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($inTransactionJournal !== null) {
                        return $this->verifyExistingReversalMatch(
                            existingJournal: $inTransactionJournal,
                            input: $input,
                            reason: $reason,
                        );
                    }

                    // 2. Lock the original financial transaction row first to serialize concurrent reversal attempts
                    /** @var FinancialTransaction|null $originalTransaction */
                    $originalTransaction = FinancialTransaction::query()
                        ->whereKey($input->originalTransactionId)
                        ->lockForUpdate()
                        ->first();

                    if ($originalTransaction === null || (int) $originalTransaction->user_id !== (int) $input->user->id) {
                        throw new LedgerIntegrityException(
                            'The transaction to reverse does not exist or is not accessible.',
                        );
                    }

                    if ($originalTransaction->isReversal()) {
                        throw new LedgerIntegrityException(
                            'A reversal transaction cannot be reversed.',
                        );
                    }

                    // 3. Check whether an existing reversal row already links to this original transaction
                    $existingReversal = FinancialTransaction::query()
                        ->where('reversed_transaction_id', $originalTransaction->id)
                        ->lockForUpdate()
                        ->first();

                    if ($existingReversal !== null) {
                        throw new LedgerIntegrityException(
                            'This financial transaction has already been reversed.',
                        );
                    }

                    // 4. Lock and validate the original journal entry and its postings
                    /** @var JournalEntry|null $originalJournal */
                    $originalJournal = JournalEntry::query()
                        ->with('postings')
                        ->whereKey($originalTransaction->journal_entry_id)
                        ->lockForUpdate()
                        ->first();

                    $reversedPostings = $this->buildValidatedReversedPostings(
                        originalTransaction: $originalTransaction,
                        originalJournal: $originalJournal,
                    );

                    // 5. Lock financial accounts, category, and currency in strict global lock order:
                    // financial_accounts -> categories -> currencies -> ledger_accounts
                    $accountIdsToLock = [(int) $originalTransaction->financial_account_id];
                    if ($originalTransaction->transfer_financial_account_id !== null) {
                        $accountIdsToLock[] = (int) $originalTransaction->transfer_financial_account_id;
                        sort($accountIdsToLock);
                    }

                    $lockedAccounts = FinancialAccount::query()
                        ->whereIn('id', $accountIdsToLock)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');

                    if ($lockedAccounts->count() !== count(array_unique($accountIdsToLock))) {
                        throw new LedgerIntegrityException(
                            'Original transaction references a financial account that does not exist.',
                        );
                    }

                    if ($originalTransaction->category_id !== null) {
                        $lockedCategory = Category::query()
                            ->whereKey((int) $originalTransaction->category_id)
                            ->lockForUpdate()
                            ->first();

                        if ($lockedCategory === null || (int) $lockedCategory->user_id !== (int) $input->user->id) {
                            throw new LedgerIntegrityException(
                                'Original transaction references a category that does not exist or is not accessible.',
                            );
                        }
                    }

                    $reversalEntryDate = $originalTransaction->entry_date->format('Y-m-d');
                    $reversalDescription = $this->buildReversalDescription($originalTransaction->description);
                    $canonicalReference = $this->computeCanonicalReversalReference(
                        userId: (int) $input->user->id,
                        originalTransactionId: (int) $originalTransaction->id,
                        originalJournalEntryId: (int) $originalTransaction->journal_entry_id,
                        currencyCode: $originalTransaction->currency_code,
                        amountMinor: (int) $originalTransaction->amount_minor,
                        entryDate: $reversalEntryDate,
                        reason: $reason,
                    );

                    $ledgerResult = $this->ledgerPostingService->post(new PostJournalEntryInput(
                        user: $input->user,
                        currencyCode: $originalTransaction->currency_code,
                        entryDate: $reversalEntryDate,
                        description: $reversalDescription,
                        idempotencyKey: $idempotencyKey,
                        postings: $reversedPostings,
                        externalReference: $canonicalReference,
                    ));

                    $reversalJournalEntry = $ledgerResult->journalEntry;

                    if (! $ledgerResult->wasRecentlyCreated) {
                        $existingJournalWithTx = JournalEntry::query()
                            ->with([
                                'postings',
                                'financialTransaction.reversedTransaction.journalEntry.postings',
                                'financialTransaction.financialAccount',
                                'financialTransaction.transferFinancialAccount',
                                'financialTransaction.category',
                            ])
                            ->whereKey($reversalJournalEntry->id)
                            ->firstOrFail();

                        return $this->verifyExistingReversalMatch(
                            existingJournal: $existingJournalWithTx,
                            input: $input,
                            reason: $reason,
                        );
                    }

                    $reversalTransaction = FinancialTransaction::query()->create([
                        'user_id' => $input->user->id,
                        'journal_entry_id' => $reversalJournalEntry->id,
                        'reversed_transaction_id' => $originalTransaction->id,
                        'financial_account_id' => $originalTransaction->financial_account_id,
                        'transfer_financial_account_id' => $originalTransaction->transfer_financial_account_id,
                        'category_id' => $originalTransaction->category_id,
                        'type' => $originalTransaction->type,
                        'currency_code' => $originalTransaction->currency_code,
                        'amount_minor' => (int) $originalTransaction->amount_minor,
                        'entry_date' => $reversalEntryDate,
                        'description' => $reversalDescription,
                        'notes' => null,
                        'reversal_reason' => $reason,
                    ]);

                    $this->auditService->record(
                        actor: $input->user,
                        action: AuditService::ACTION_TRANSACTION_REVERSED,
                        auditableType: FinancialTransaction::class,
                        auditableId: $reversalTransaction->id,
                        metadata: [
                            'original_transaction_id' => (int) $originalTransaction->id,
                            'original_journal_entry_id' => (int) $originalTransaction->journal_entry_id,
                            'reversal_transaction_id' => (int) $reversalTransaction->id,
                            'reversal_journal_entry_id' => (int) $reversalJournalEntry->id,
                            'type' => $originalTransaction->type->value,
                            'currency_code' => $originalTransaction->currency_code,
                            'amount_minor' => (int) $originalTransaction->amount_minor,
                            'entry_date' => $reversalEntryDate,
                            'financial_account_id' => (int) $originalTransaction->financial_account_id,
                            'transfer_financial_account_id' => $originalTransaction->transfer_financial_account_id !== null
                                ? (int) $originalTransaction->transfer_financial_account_id
                                : null,
                            'category_id' => $originalTransaction->category_id !== null
                                ? (int) $originalTransaction->category_id
                                : null,
                            'reversal_reason' => $reason,
                            'idempotency_key' => $idempotencyKey,
                        ],
                    );

                    return new ReversedFinancialTransactionResult(
                        reversalTransaction: $reversalTransaction->load([
                            'reversedTransaction',
                            'financialAccount',
                            'transferFinancialAccount',
                            'category',
                            'journalEntry.postings',
                        ]),
                        originalTransaction: $originalTransaction->load([
                            'reversalTransaction',
                            'financialAccount',
                            'transferFinancialAccount',
                            'category',
                            'journalEntry.postings',
                        ]),
                        reversalJournalEntry: $reversalJournalEntry,
                        wasRecentlyCreated: true,
                    );
                });
            } catch (UniqueConstraintViolationException|QueryException $exception) {
                if ($this->isReversedTransactionUniqueViolation($exception)) {
                    throw new LedgerIntegrityException(
                        'This financial transaction has already been reversed.',
                        previous: $exception,
                    );
                }

                if (
                    $attempt < self::MAX_CONCURRENCY_ATTEMPTS
                    && (
                        $this->isJournalEntryIdempotencyConstraintViolation($exception)
                        || $this->isDeadlockOrSerializationFailure($exception)
                    )
                ) {
                    continue;
                }

                if ($this->isJournalEntryIdempotencyConstraintViolation($exception)) {
                    $recoveredJournal = JournalEntry::query()
                        ->with([
                            'postings',
                            'financialTransaction.reversedTransaction.journalEntry.postings',
                            'financialTransaction.financialAccount',
                            'financialTransaction.transferFinancialAccount',
                            'financialTransaction.category',
                        ])
                        ->where('user_id', $input->user->id)
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();

                    if ($recoveredJournal !== null) {
                        return $this->verifyExistingReversalMatch(
                            existingJournal: $recoveredJournal,
                            input: $input,
                            reason: $reason,
                        );
                    }
                }

                throw $exception;
            }
        }

        throw new LedgerIntegrityException('Unable to complete transaction reversal due to concurrent database contention.');
    }

    /**
     * Computes a deterministic external_reference digest representing the canonical meaning
     * of a financial transaction reversal.
     */
    public function computeCanonicalReversalReference(
        int $userId,
        int $originalTransactionId,
        int $originalJournalEntryId,
        string $currencyCode,
        int $amountMinor,
        string $entryDate,
        ?string $reason,
    ): string {
        $payload = [
            'user_id' => $userId,
            'original_transaction_id' => $originalTransactionId,
            'original_journal_entry_id' => $originalJournalEntryId,
            'currency_code' => $currencyCode,
            'amount_minor' => $amountMinor,
            'entry_date' => $entryDate,
            'reversal_reason' => $reason,
        ];

        return 'REV:'.hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Must be called inside an active transaction where $lockedCategory has already been locked
     * with lockForUpdate() and validated for ownership and archive status.
     */
    private function resolveOrCreateCategoryLedgerAccountInTransaction(
        User $user,
        Category $lockedCategory,
        string $currencyCode,
    ): LedgerAccount {
        $existing = LedgerAccount::query()
            ->where('category_id', $lockedCategory->id)
            ->where('currency_code', $currencyCode)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            if (
                (int) $existing->user_id !== (int) $user->id
                || $existing->financial_account_id !== null
                || $existing->classification !== $lockedCategory->type->ledgerClassification()
                || $existing->currency_code !== $currencyCode
            ) {
                throw new LedgerIntegrityException(
                    'Existing category ledger account does not match expected ownership, currency, or classification.',
                );
            }

            return $existing;
        }

        return LedgerAccount::query()->create([
            'user_id' => $user->id,
            'financial_account_id' => null,
            'category_id' => $lockedCategory->id,
            'code' => sprintf('CAT-%d-%s', $lockedCategory->id, $currencyCode),
            'name' => sprintf('%s (%s)', $lockedCategory->name, $currencyCode),
            'classification' => $lockedCategory->type->ledgerClassification(),
            'currency_code' => $currencyCode,
            'is_system' => false,
        ]);
    }

    /**
     * Verifies that an existing journal entry found by idempotency key has a linked FinancialTransaction
     * and matches the complete canonical meaning and posting fingerprint of the retry request.
     *
     * Note: Does not require the linked account(s) or category to remain unarchived if the transaction
     * was already committed prior to archiving.
     */
    private function verifyExistingTransactionMatch(
        JournalEntry $existingJournal,
        CreateFinancialTransactionInput $input,
        FinancialTransactionType $transactionType,
        string $entryDate,
        string $description,
        ?string $notes,
    ): CreatedFinancialTransactionResult {
        $existingTransaction = $existingJournal->financialTransaction;

        if ($existingTransaction === null) {
            throw new IdempotencyConflictException(
                'Idempotency key has already been used by a journal entry without a linked financial transaction.',
            );
        }

        if ($existingTransaction->reversed_transaction_id !== null) {
            throw new IdempotencyConflictException(
                'Idempotency key has already been used for a transaction reversal.',
            );
        }

        $currency = Currency::query()->find($existingTransaction->currency_code);
        if ($currency === null) {
            throw new IdempotencyConflictException(
                'Idempotency key payload cannot be verified against currency precision.',
            );
        }

        try {
            $requestedAmountMinor = MoneyInputParser::parseToMinorUnits(
                $input->amount,
                $currency->minor_unit_exponent,
            );
        } catch (LedgerIntegrityException $exception) {
            throw new IdempotencyConflictException(
                'Idempotency key has already been used with a different transaction payload.',
                previous: $exception,
            );
        }

        // 1. Verify stored FinancialTransaction columns directly against the retry request
        $transferAccountId = $transactionType === FinancialTransactionType::Transfer
            ? $input->transferFinancialAccountId
            : null;
        $categoryId = $transactionType === FinancialTransactionType::Transfer
            ? null
            : $input->categoryId;

        if (
            (int) $existingTransaction->user_id !== (int) $input->user->id
            || (int) $existingJournal->user_id !== (int) $input->user->id
            || $existingTransaction->type !== $transactionType
            || (int) $existingTransaction->financial_account_id !== $input->financialAccountId
            || ($existingTransaction->transfer_financial_account_id !== null ? (int) $existingTransaction->transfer_financial_account_id : null) !== $transferAccountId
            || ($existingTransaction->category_id !== null ? (int) $existingTransaction->category_id : null) !== $categoryId
            || $existingTransaction->currency_code !== $existingJournal->currency_code
            || (int) $existingTransaction->amount_minor !== $requestedAmountMinor
            || $existingTransaction->entry_date->format('Y-m-d') !== $entryDate
            || $existingJournal->entry_date->format('Y-m-d') !== $entryDate
            || $existingTransaction->description !== $description
            || $existingJournal->description !== $description
            || $existingTransaction->notes !== $notes
        ) {
            throw new IdempotencyConflictException(
                'Idempotency key has already been used with a different transaction payload.',
            );
        }

        // 2. Verify canonical external_reference digest
        $expectedReference = $this->computeCanonicalTransactionReference(
            userId: (int) $input->user->id,
            type: $transactionType,
            financialAccountId: $input->financialAccountId,
            transferFinancialAccountId: $transferAccountId,
            categoryId: $categoryId,
            currencyCode: $existingTransaction->currency_code,
            amountMinor: $requestedAmountMinor,
            entryDate: $entryDate,
            description: $description,
            notes: $notes,
        );

        if (! hash_equals((string) $existingJournal->external_reference, $expectedReference)) {
            throw new IdempotencyConflictException(
                'Idempotency key has already been used with a different transaction payload.',
            );
        }

        // 3. Reconstruct expected double-entry postings from the linked accounts/category and verify journal request_fingerprint
        $primaryLedgerAccount = $existingTransaction->financialAccount?->ledgerAccount;
        if ($primaryLedgerAccount === null) {
            throw new IdempotencyConflictException(
                'Existing transaction primary account is missing its linked ledger account.',
            );
        }

        $expectedPostings = [];

        if ($transactionType === FinancialTransactionType::Transfer) {
            $destinationLedgerAccount = $existingTransaction->transferFinancialAccount?->ledgerAccount;
            if ($destinationLedgerAccount === null) {
                throw new IdempotencyConflictException(
                    'Existing transaction transfer destination account is missing its linked ledger account.',
                );
            }

            $expectedPostings = [
                new PostingInstruction(
                    ledgerAccountId: (int) $destinationLedgerAccount->id,
                    debitAmountMinor: $requestedAmountMinor,
                    creditAmountMinor: 0,
                ),
                new PostingInstruction(
                    ledgerAccountId: (int) $primaryLedgerAccount->id,
                    debitAmountMinor: 0,
                    creditAmountMinor: $requestedAmountMinor,
                ),
            ];
        } else {
            $categoryLedgerAccount = LedgerAccount::query()
                ->where('category_id', $categoryId)
                ->where('currency_code', $existingTransaction->currency_code)
                ->first();

            if ($categoryLedgerAccount === null) {
                throw new IdempotencyConflictException(
                    'Existing transaction category is missing its currency ledger account.',
                );
            }

            if ($transactionType === FinancialTransactionType::Income) {
                $expectedPostings = [
                    new PostingInstruction(
                        ledgerAccountId: (int) $primaryLedgerAccount->id,
                        debitAmountMinor: $requestedAmountMinor,
                        creditAmountMinor: 0,
                    ),
                    new PostingInstruction(
                        ledgerAccountId: (int) $categoryLedgerAccount->id,
                        debitAmountMinor: 0,
                        creditAmountMinor: $requestedAmountMinor,
                    ),
                ];
            } else {
                $expectedPostings = [
                    new PostingInstruction(
                        ledgerAccountId: (int) $categoryLedgerAccount->id,
                        debitAmountMinor: $requestedAmountMinor,
                        creditAmountMinor: 0,
                    ),
                    new PostingInstruction(
                        ledgerAccountId: (int) $primaryLedgerAccount->id,
                        debitAmountMinor: 0,
                        creditAmountMinor: $requestedAmountMinor,
                    ),
                ];
            }
        }

        $expectedJournalFingerprint = $this->ledgerPostingService->computeRequestFingerprint(
            userId: (int) $input->user->id,
            currencyCode: $existingTransaction->currency_code,
            entryDate: $entryDate,
            description: $description,
            externalReference: $expectedReference,
            postings: $expectedPostings,
        );

        if (! hash_equals((string) $existingJournal->request_fingerprint, $expectedJournalFingerprint)) {
            throw new IdempotencyConflictException(
                'Existing journal entry posting fingerprint does not match the transaction payload.',
            );
        }

        // Also verify actual persisted journal_postings rows match expected postings
        $existingJournal->loadMissing('postings');
        $actualPostings = $existingJournal->postings
            ->map(static fn ($posting): PostingInstruction => new PostingInstruction(
                ledgerAccountId: (int) $posting->ledger_account_id,
                debitAmountMinor: (int) $posting->debit_amount_minor,
                creditAmountMinor: (int) $posting->credit_amount_minor,
            ))
            ->values()
            ->all();

        $actualPostingsFingerprint = $this->ledgerPostingService->computeRequestFingerprint(
            userId: (int) $input->user->id,
            currencyCode: $existingTransaction->currency_code,
            entryDate: $entryDate,
            description: $description,
            externalReference: $expectedReference,
            postings: $actualPostings,
        );

        if (! hash_equals($expectedJournalFingerprint, $actualPostingsFingerprint)) {
            throw new IdempotencyConflictException(
                'Persisted journal postings do not match the expected transaction postings.',
            );
        }

        return new CreatedFinancialTransactionResult(
            transaction: $existingTransaction->loadMissing([
                'financialAccount',
                'transferFinancialAccount',
                'category',
                'journalEntry.postings',
            ]),
            journalEntry: $existingJournal,
            wasRecentlyCreated: false,
        );
    }

    /**
     * Validates the original transaction's journal entry and constructs the exact opposite
     * double-entry postings (every original debit becomes a reversal credit and every
     * original credit becomes a reversal debit).
     *
     * @return list<PostingInstruction>
     */
    private function buildValidatedReversedPostings(
        FinancialTransaction $originalTransaction,
        ?JournalEntry $originalJournal,
    ): array {
        if ($originalJournal === null) {
            throw new LedgerIntegrityException(
                'Original transaction is missing its linked journal entry.',
            );
        }

        if (
            (int) $originalJournal->user_id !== (int) $originalTransaction->user_id
            || $originalJournal->currency_code !== $originalTransaction->currency_code
            || $originalJournal->status !== JournalEntryStatus::Posted
        ) {
            throw new LedgerIntegrityException(
                'Original journal entry ownership, currency, or status is inconsistent with the transaction.',
            );
        }

        $originalPostings = $originalJournal->postings;

        if ($originalPostings->count() < 2) {
            throw new LedgerIntegrityException(
                'Original journal entry does not contain a valid balanced set of postings.',
            );
        }

        $totalDebits = 0;
        $totalCredits = 0;
        $reversedPostings = [];

        foreach ($originalPostings as $posting) {
            $debit = (int) $posting->debit_amount_minor;
            $credit = (int) $posting->credit_amount_minor;

            if ($debit < 0 || $credit < 0 || (($debit === 0 && $credit === 0) || ($debit > 0 && $credit > 0))) {
                throw new LedgerIntegrityException(
                    'Original journal entry contains an invalid posting line.',
                );
            }

            $totalDebits = Money::checkedAdd($totalDebits, $debit);
            $totalCredits = Money::checkedAdd($totalCredits, $credit);

            $reversedPostings[] = new PostingInstruction(
                ledgerAccountId: (int) $posting->ledger_account_id,
                debitAmountMinor: $credit,
                creditAmountMinor: $debit,
            );
        }

        if ($totalDebits !== $totalCredits || $totalDebits !== (int) $originalTransaction->amount_minor) {
            throw new LedgerIntegrityException(
                'Original journal entry postings do not match the transaction amount.',
            );
        }

        return $reversedPostings;
    }

    /**
     * Verifies that an existing journal entry found by idempotency key belongs to a reversal
     * FinancialTransaction and matches the full canonical reversal payload and inverted postings.
     */
    private function verifyExistingReversalMatch(
        JournalEntry $existingJournal,
        ReverseFinancialTransactionInput $input,
        ?string $reason,
    ): ReversedFinancialTransactionResult {
        $existingReversal = $existingJournal->financialTransaction;

        if ($existingReversal === null) {
            throw new IdempotencyConflictException(
                'Idempotency key has already been used by a journal entry without a linked financial transaction.',
            );
        }

        if ($existingReversal->reversed_transaction_id === null) {
            throw new IdempotencyConflictException(
                'Idempotency key has already been used for a non-reversal financial transaction.',
            );
        }

        $originalTransaction = $existingReversal->reversedTransaction;

        if ($originalTransaction === null) {
            throw new IdempotencyConflictException(
                'Existing reversal transaction is missing its linked original transaction.',
            );
        }

        $expectedDescription = $this->buildReversalDescription($originalTransaction->description);
        $expectedEntryDate = $originalTransaction->entry_date->format('Y-m-d');

        if (
            (int) $existingReversal->user_id !== (int) $input->user->id
            || (int) $existingJournal->user_id !== (int) $input->user->id
            || (int) $originalTransaction->user_id !== (int) $input->user->id
            || (int) $existingReversal->reversed_transaction_id !== $input->originalTransactionId
            || (int) $originalTransaction->id !== $input->originalTransactionId
            || $existingReversal->reversal_reason !== $reason
            || $existingReversal->type !== $originalTransaction->type
            || (int) $existingReversal->financial_account_id !== (int) $originalTransaction->financial_account_id
            || ($existingReversal->transfer_financial_account_id !== null ? (int) $existingReversal->transfer_financial_account_id : null)
                !== ($originalTransaction->transfer_financial_account_id !== null ? (int) $originalTransaction->transfer_financial_account_id : null)
            || ($existingReversal->category_id !== null ? (int) $existingReversal->category_id : null)
                !== ($originalTransaction->category_id !== null ? (int) $originalTransaction->category_id : null)
            || $existingReversal->currency_code !== $originalTransaction->currency_code
            || $existingJournal->currency_code !== $originalTransaction->currency_code
            || (int) $existingReversal->amount_minor !== (int) $originalTransaction->amount_minor
            || $existingReversal->entry_date->format('Y-m-d') !== $expectedEntryDate
            || $existingJournal->entry_date->format('Y-m-d') !== $expectedEntryDate
            || $existingReversal->description !== $expectedDescription
            || $existingJournal->description !== $expectedDescription
        ) {
            throw new IdempotencyConflictException(
                'Idempotency key has already been used with a different reversal payload.',
            );
        }

        $expectedReference = $this->computeCanonicalReversalReference(
            userId: (int) $input->user->id,
            originalTransactionId: (int) $originalTransaction->id,
            originalJournalEntryId: (int) $originalTransaction->journal_entry_id,
            currencyCode: $originalTransaction->currency_code,
            amountMinor: (int) $originalTransaction->amount_minor,
            entryDate: $expectedEntryDate,
            reason: $reason,
        );

        if (! hash_equals((string) $existingJournal->external_reference, $expectedReference)) {
            throw new IdempotencyConflictException(
                'Idempotency key has already been used with a different reversal payload.',
            );
        }

        $originalTransaction->loadMissing('journalEntry.postings');
        $expectedReversedPostings = $this->buildValidatedReversedPostings(
            originalTransaction: $originalTransaction,
            originalJournal: $originalTransaction->journalEntry,
        );

        $expectedJournalFingerprint = $this->ledgerPostingService->computeRequestFingerprint(
            userId: (int) $input->user->id,
            currencyCode: $originalTransaction->currency_code,
            entryDate: $expectedEntryDate,
            description: $expectedDescription,
            externalReference: $expectedReference,
            postings: $expectedReversedPostings,
        );

        if (! hash_equals((string) $existingJournal->request_fingerprint, $expectedJournalFingerprint)) {
            throw new IdempotencyConflictException(
                'Existing reversal journal entry posting fingerprint does not match the expected reversal postings.',
            );
        }

        $existingJournal->loadMissing('postings');
        $actualPostings = $existingJournal->postings
            ->map(static fn ($posting): PostingInstruction => new PostingInstruction(
                ledgerAccountId: (int) $posting->ledger_account_id,
                debitAmountMinor: (int) $posting->debit_amount_minor,
                creditAmountMinor: (int) $posting->credit_amount_minor,
            ))
            ->values()
            ->all();

        $actualPostingsFingerprint = $this->ledgerPostingService->computeRequestFingerprint(
            userId: (int) $input->user->id,
            currencyCode: $originalTransaction->currency_code,
            entryDate: $expectedEntryDate,
            description: $expectedDescription,
            externalReference: $expectedReference,
            postings: $actualPostings,
        );

        if (! hash_equals($expectedJournalFingerprint, $actualPostingsFingerprint)) {
            throw new IdempotencyConflictException(
                'Persisted reversal journal postings do not match the expected opposite postings.',
            );
        }

        return new ReversedFinancialTransactionResult(
            reversalTransaction: $existingReversal->loadMissing([
                'reversedTransaction',
                'financialAccount',
                'transferFinancialAccount',
                'category',
                'journalEntry.postings',
            ]),
            originalTransaction: $originalTransaction->loadMissing([
                'reversalTransaction',
                'financialAccount',
                'transferFinancialAccount',
                'category',
                'journalEntry.postings',
            ]),
            reversalJournalEntry: $existingJournal,
            wasRecentlyCreated: false,
        );
    }

    private function buildReversalDescription(string $originalDescription): string
    {
        return Str::limit('Reversal: '.trim($originalDescription), 255, '');
    }

    private function normalizeAndValidateReversalReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        $trimmed = trim($reason);

        if ($trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed) > self::MAX_REVERSAL_REASON_LENGTH) {
            throw new LedgerIntegrityException(sprintf(
                'Reversal reason must not exceed %d characters.',
                self::MAX_REVERSAL_REASON_LENGTH,
            ));
        }

        return $trimmed;
    }

    private function isReversedTransactionUniqueViolation(QueryException $exception): bool
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

        return str_contains($message, self::REVERSED_TRANSACTION_UNIQUE_INDEX)
            || str_contains($message, 'financial_transactions.reversed_transaction_id');
    }

    private function isRetryableConcurrencyException(QueryException $exception): bool
    {
        return $this->isJournalEntryIdempotencyConstraintViolation($exception)
            || $this->isCategoryLedgerUniqueViolation($exception)
            || $this->isDeadlockOrSerializationFailure($exception);
    }

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

        return str_contains($message, LedgerPostingService::IDEMPOTENCY_UNIQUE_INDEX)
            || (
                str_contains($message, 'journal_entries.user_id')
                && str_contains($message, 'journal_entries.idempotency_key')
            );
    }

    private function isCategoryLedgerUniqueViolation(QueryException $exception): bool
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

        return str_contains($message, self::CATEGORY_LEDGER_UNIQUE_INDEX)
            || (
                str_contains($message, 'ledger_accounts.category_id')
                && str_contains($message, 'ledger_accounts.currency_code')
            );
    }

    private function isDeadlockOrSerializationFailure(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = strtolower($exception->getMessage());

        // MySQL/MariaDB: 40001 / 1213 (deadlock), 1205 (lock wait timeout); PostgreSQL: 40P01 / 40001; SQLite: 5 / 6 (database/table is locked)
        return $sqlState === '40001'
            || $sqlState === '40P01'
            || in_array($driverCode, [1213, 1205, 5, 6], true)
            || str_contains($message, 'deadlock')
            || str_contains($message, 'database is locked');
    }

    private function assertValidUser(User $user): void
    {
        if (! $user->exists || $user->id === null) {
            throw new LedgerIntegrityException('Authenticated user is required.');
        }
    }

    private function resolveTransactionType(FinancialTransactionType|string $type): FinancialTransactionType
    {
        if ($type instanceof FinancialTransactionType) {
            return $type;
        }

        try {
            return FinancialTransactionType::from(strtolower(trim($type)));
        } catch (ValueError $exception) {
            throw new LedgerIntegrityException(
                'Transaction type must be income, expense, or transfer.',
                previous: $exception,
            );
        }
    }

    /**
     * Validates that the client-supplied idempotency key is a standard 36-character UUID
     * and normalizes it to lowercase so uppercase and lowercase representations of the
     * same UUID map to the same canonical idempotency key.
     */
    private function validateUuidIdempotencyKey(string $idempotencyKey): string
    {
        $normalized = strtolower(trim($idempotencyKey));

        if (! Str::isUuid($normalized)) {
            throw new LedgerIntegrityException('Transaction idempotency key must be a valid UUID.');
        }

        return $normalized;
    }

    private function validateEntryDate(string $entryDate): string
    {
        $trimmed = trim($entryDate);

        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $trimmed) !== 1) {
            throw new LedgerIntegrityException('Transaction entry date must be a valid YYYY-MM-DD date.');
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $trimmed);
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $parsed === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $trimmed
        ) {
            throw new LedgerIntegrityException('Transaction entry date must be a valid calendar date.');
        }

        return $trimmed;
    }

    private function validateDescription(string $description): string
    {
        $trimmed = trim($description);

        if ($trimmed === '' || mb_strlen($trimmed) > 255) {
            throw new LedgerIntegrityException('Transaction description (1 to 255 characters) is required.');
        }

        return $trimmed;
    }

    private function normalizeAndValidateNotes(?string $notes): ?string
    {
        if ($notes === null) {
            return null;
        }

        $trimmed = trim($notes);

        if ($trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed) > self::MAX_NOTES_LENGTH) {
            throw new LedgerIntegrityException(sprintf(
                'Transaction notes must not exceed %d characters.',
                self::MAX_NOTES_LENGTH,
            ));
        }

        return $trimmed;
    }

    private function validateTypeSpecificInputs(
        FinancialTransactionType $type,
        int $financialAccountId,
        ?int $transferFinancialAccountId,
        ?int $categoryId,
    ): void {
        if ($financialAccountId <= 0) {
            throw new LedgerIntegrityException('A valid financial account is required.');
        }

        if ($type === FinancialTransactionType::Transfer) {
            if ($categoryId !== null) {
                throw new LedgerIntegrityException('Transfer transactions cannot specify a category.');
            }

            if ($transferFinancialAccountId === null || $transferFinancialAccountId <= 0) {
                throw new LedgerIntegrityException(
                    'Transfer transactions require a valid destination financial account.',
                );
            }

            if ($transferFinancialAccountId === $financialAccountId) {
                throw new LedgerIntegrityException(
                    'Transfer source and destination financial accounts must be distinct.',
                );
            }

            return;
        }

        if ($transferFinancialAccountId !== null) {
            throw new LedgerIntegrityException(
                'Income and expense transactions cannot specify a transfer destination account.',
            );
        }

        if ($categoryId === null || $categoryId <= 0) {
            throw new LedgerIntegrityException('Income and expense transactions require a valid category.');
        }
    }
}
