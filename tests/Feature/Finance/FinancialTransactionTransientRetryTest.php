<?php

use App\DataTransferObjects\CreatedFinancialTransactionResult;
use App\DataTransferObjects\CreateFinancialTransactionInput;
use App\DataTransferObjects\ReverseFinancialTransactionInput;
use App\Enums\CategoryType;
use App\Enums\FinancialTransactionType;
use App\Exceptions\IdempotencyConflictException;
use App\Models\AuditEvent;
use App\Models\FinancialTransaction;
use App\Models\JournalEntry;
use App\Models\JournalPosting;
use App\Models\User;
use App\Services\AccountBalanceService;
use App\Services\AuditService;
use App\Services\CategoryService;
use App\Services\FinancialAccountService;
use App\Services\FinancialTransactionService;
use App\Services\LedgerPostingService;
use App\Support\TransientTransactionFailure;
use App\Support\TransientTransactionRetryBackoff;
use Database\Seeders\CurrencySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CurrencySeeder::class);
    TransientTransactionRetryBackoff::resetSleeper();
});

afterEach(function (): void {
    TransientTransactionRetryBackoff::resetSleeper();
});

/**
 * @param  list<QueryException>  $failures
 */
function flakyFinancialTransactionService(array $failures): FinancialTransactionService
{
    return new class($failures, app(LedgerPostingService::class), app(AuditService::class)) extends FinancialTransactionService
    {
        /** @param  list<QueryException>  $failures */
        public function __construct(
            private array $failures,
            LedgerPostingService $ledgerPostingService,
            AuditService $auditService,
        ) {
            parent::__construct($ledgerPostingService, $auditService);
        }

        public int $attemptCount = 0;

        protected function executeCreateTransactionAttempt(callable $attempt): mixed
        {
            $this->attemptCount++;

            if ($this->failures !== []) {
                throw array_shift($this->failures);
            }

            return parent::executeCreateTransactionAttempt($attempt);
        }

        protected function executeReverseTransactionAttempt(callable $attempt): mixed
        {
            $this->attemptCount++;

            if ($this->failures !== []) {
                throw array_shift($this->failures);
            }

            return parent::executeReverseTransactionAttempt($attempt);
        }
    };
}

function syntheticSqliteBusy(): QueryException
{
    $previous = new PDOException('SQLSTATE[HY000]: General error: 5 database is locked');
    $previous->errorInfo = ['HY000', TransientTransactionFailure::SQLITE_BUSY, 'database is locked'];

    return new QueryException('sqlite', 'insert into "ledger_accounts"', [], $previous);
}

/**
 * @return array{
 *     user: User,
 *     accountId: int,
 *     categoryId: int,
 *     openingMinor: int,
 *     transactionService: FinancialTransactionService,
 *     balanceService: AccountBalanceService
 * }
 */
function transientRetryFixtures(): array
{
    $user = User::factory()->create();
    $account = app(FinancialAccountService::class)->createAccountResult($user, [
        'name' => 'Retry Checking',
        'type' => 'checking',
        'currency_code' => 'PHP',
        'starting_balance' => '1000.00',
        'idempotency_key' => (string) Str::uuid(),
    ])->account->load('ledgerAccount');

    $category = app(CategoryService::class)->createCategory($user, [
        'name' => 'Retry Groceries',
        'type' => CategoryType::Expense,
    ]);

    return [
        'user' => $user,
        'accountId' => $account->id,
        'categoryId' => $category->id,
        'openingMinor' => 100_000,
        'transactionService' => app(FinancialTransactionService::class),
        'balanceService' => app(AccountBalanceService::class),
        'ledgerAccount' => $account->ledgerAccount,
    ];
}

test('sqlite busy retry backs off between attempts then succeeds without partial rows', function (): void {
    $fixtures = transientRetryFixtures();
    $slept = [];
    TransientTransactionRetryBackoff::setSleeper(function (int $micros) use (&$slept): void {
        $slept[] = $micros;
    });

    $service = flakyFinancialTransactionService([syntheticSqliteBusy()]);

    $created = $service->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: $fixtures['accountId'],
        amount: '10.00',
        entryDate: '2026-11-03',
        description: 'After busy retry',
        idempotencyKey: (string) Str::uuid(),
        categoryId: $fixtures['categoryId'],
    ));

    expect($created->wasRecentlyCreated)->toBeTrue()
        ->and($service->attemptCount)->toBe(2)
        ->and($slept)->toHaveCount(1)
        ->and($slept[0])->toBeGreaterThanOrEqual(10_000)->toBeLessThanOrEqual(25_000)
        ->and(FinancialTransaction::query()->count())->toBe(1)
        ->and(JournalEntry::query()->where('status', 'posted')->count())->toBeGreaterThanOrEqual(1)
        ->and(AuditEvent::query()->where('action', AuditService::ACTION_TRANSACTION_RECORDED)->count())->toBe(1);

    $balance = $fixtures['balanceService']->getBalanceForLedgerAccount($fixtures['ledgerAccount'])->amountMinor;
    expect($balance)->toBe($fixtures['openingMinor'] - 1_000);
});

test('sqlite busy exhaustion does not sleep after the final attempt and leaves no partial postings', function (): void {
    $fixtures = transientRetryFixtures();
    $txnsBefore = FinancialTransaction::query()->count();
    $journalsBefore = JournalEntry::query()->count();
    $postingsBefore = JournalPosting::query()->count();
    $auditsBefore = AuditEvent::query()->where('action', AuditService::ACTION_TRANSACTION_RECORDED)->count();

    $slept = [];
    TransientTransactionRetryBackoff::setSleeper(function (int $micros) use (&$slept): void {
        $slept[] = $micros;
    });

    $service = flakyFinancialTransactionService([
        syntheticSqliteBusy(),
        syntheticSqliteBusy(),
        syntheticSqliteBusy(),
    ]);

    expect(fn () => $service->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: $fixtures['accountId'],
        amount: '12.00',
        entryDate: '2026-11-03',
        description: 'Exhausted busy',
        idempotencyKey: (string) Str::uuid(),
        categoryId: $fixtures['categoryId'],
    )))->toThrow(QueryException::class);

    expect($service->attemptCount)->toBe(FinancialTransactionService::MAX_CONCURRENCY_ATTEMPTS)
        ->and($slept)->toHaveCount(2)
        ->and(FinancialTransaction::query()->count())->toBe($txnsBefore)
        ->and(JournalEntry::query()->count())->toBe($journalsBefore)
        ->and(JournalPosting::query()->count())->toBe($postingsBefore)
        ->and(AuditEvent::query()->where('action', AuditService::ACTION_TRANSACTION_RECORDED)->count())->toBe($auditsBefore);

    $balance = $fixtures['balanceService']->getBalanceForLedgerAccount($fixtures['ledgerAccount'])->amountMinor;
    expect($balance)->toBe($fixtures['openingMinor']);
});

test('non-retryable query failures are not retried and do not backoff', function (): void {
    $fixtures = transientRetryFixtures();
    $slept = [];
    TransientTransactionRetryBackoff::setSleeper(function (int $micros) use (&$slept): void {
        $slept[] = $micros;
    });

    $previous = new PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 FOREIGN KEY constraint failed');
    $previous->errorInfo = ['23000', 19, 'FOREIGN KEY constraint failed'];
    $fk = new QueryException('sqlite', 'insert into "journal_postings"', [], $previous);

    $service = flakyFinancialTransactionService([$fk]);

    expect(fn () => $service->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: $fixtures['accountId'],
        amount: '5.00',
        entryDate: '2026-11-03',
        description: 'FK failure',
        idempotencyKey: (string) Str::uuid(),
        categoryId: $fixtures['categoryId'],
    )))->toThrow(QueryException::class);

    expect($service->attemptCount)->toBe(1)
        ->and($slept)->toBe([])
        ->and(FinancialTransaction::query()->count())->toBe(0);
});

test('create recovers from sqlite busy then idempotent replay stays a single financial effect', function (): void {
    $fixtures = transientRetryFixtures();
    $key = (string) Str::uuid();
    $input = new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: $fixtures['accountId'],
        amount: '15.00',
        entryDate: '2026-11-03',
        description: 'Idempotent after busy',
        idempotencyKey: $key,
        categoryId: $fixtures['categoryId'],
    );

    $slept = [];
    TransientTransactionRetryBackoff::setSleeper(function (int $micros) use (&$slept): void {
        $slept[] = $micros;
    });

    // First create: one transient busy, then commit. Exercises retry + backoff.
    $createService = flakyFinancialTransactionService([syntheticSqliteBusy()]);
    $first = $createService->createTransaction($input);

    expect($first->wasRecentlyCreated)->toBeTrue()
        ->and($createService->attemptCount)->toBe(2)
        ->and($slept)->toHaveCount(1);

    // Replay: existing journal is resolved before any write attempt (no flaky path / no sleep).
    $slept = [];
    TransientTransactionRetryBackoff::setSleeper(function (int $micros) use (&$slept): void {
        $slept[] = $micros;
    });
    $replayService = flakyFinancialTransactionService([syntheticSqliteBusy()]);
    $replay = $replayService->createTransaction($input);

    expect($replay->wasRecentlyCreated)->toBeFalse()
        ->and($replay->transaction->id)->toBe($first->transaction->id)
        ->and(JournalEntry::query()->where('idempotency_key', $key)->count())->toBe(1)
        ->and(FinancialTransaction::query()->whereKey($first->transaction->id)->count())->toBe(1)
        ->and($replayService->attemptCount)->toBe(0)
        ->and($slept)->toBe([]);

    $balance = $fixtures['balanceService']->getBalanceForLedgerAccount($fixtures['ledgerAccount'])->amountMinor;
    expect($balance)->toBe($fixtures['openingMinor'] - 1_500);
});

test('conflicting idempotency payload is not retried as a transient failure', function (): void {
    $fixtures = transientRetryFixtures();
    $key = (string) Str::uuid();

    $fixtures['transactionService']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: $fixtures['accountId'],
        amount: '20.00',
        entryDate: '2026-11-03',
        description: 'Original',
        idempotencyKey: $key,
        categoryId: $fixtures['categoryId'],
    ));

    $slept = [];
    TransientTransactionRetryBackoff::setSleeper(function (int $micros) use (&$slept): void {
        $slept[] = $micros;
    });

    expect(fn () => $fixtures['transactionService']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: $fixtures['accountId'],
        amount: '21.00',
        entryDate: '2026-11-03',
        description: 'Conflict',
        idempotencyKey: $key,
        categoryId: $fixtures['categoryId'],
    )))->toThrow(IdempotencyConflictException::class);

    expect($slept)->toBe([])
        ->and(JournalEntry::query()->where('idempotency_key', $key)->count())->toBe(1);

    $balance = $fixtures['balanceService']->getBalanceForLedgerAccount($fixtures['ledgerAccount'])->amountMinor;
    expect($balance)->toBe($fixtures['openingMinor'] - 2_000);
});

test('reverse path backs off on sqlite busy then completes without duplicate reversals', function (): void {
    $fixtures = transientRetryFixtures();
    $original = $fixtures['transactionService']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: $fixtures['accountId'],
        amount: '8.00',
        entryDate: '2026-11-03',
        description: 'To reverse',
        idempotencyKey: (string) Str::uuid(),
        categoryId: $fixtures['categoryId'],
    ));

    $slept = [];
    TransientTransactionRetryBackoff::setSleeper(function (int $micros) use (&$slept): void {
        $slept[] = $micros;
    });

    $service = flakyFinancialTransactionService([syntheticSqliteBusy()]);
    $reversed = $service->reverseTransaction(new ReverseFinancialTransactionInput(
        user: $fixtures['user'],
        originalTransactionId: $original->transaction->id,
        idempotencyKey: (string) Str::uuid(),
        reason: 'Retry reverse',
    ));

    expect($reversed->wasRecentlyCreated)->toBeTrue()
        ->and($service->attemptCount)->toBe(2)
        ->and($slept)->toHaveCount(1)
        ->and(FinancialTransaction::query()->whereNotNull('reversed_transaction_id')->count())->toBe(1);

    $balance = $fixtures['balanceService']->getBalanceForLedgerAccount($fixtures['ledgerAccount'])->amountMinor;
    expect($balance)->toBe($fixtures['openingMinor']);
});

test('sequential expense posts preserve ledger reconciliation for successful writes', function (): void {
    $fixtures = transientRetryFixtures();
    $service = $fixtures['transactionService'];

    $created = [];
    for ($i = 0; $i < 3; $i++) {
        $created[] = $service->createTransaction(new CreateFinancialTransactionInput(
            user: $fixtures['user'],
            type: FinancialTransactionType::Expense,
            financialAccountId: $fixtures['accountId'],
            amount: '10.00',
            entryDate: '2026-11-03',
            description: 'Sequential expense '.$i,
            idempotencyKey: (string) Str::uuid(),
            categoryId: $fixtures['categoryId'],
        ));
    }

    expect(collect($created)->every(fn (CreatedFinancialTransactionResult $r): bool => $r->wasRecentlyCreated))->toBeTrue();

    $balance = $fixtures['balanceService']->getBalanceForLedgerAccount($fixtures['ledgerAccount'])->amountMinor;
    expect($balance)->toBe($fixtures['openingMinor'] - 3_000);

    $row = DB::table('journal_postings as jp')
        ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
        ->where('jp.ledger_account_id', $fixtures['ledgerAccount']->id)
        ->where('je.status', 'posted')
        ->selectRaw('COALESCE(SUM(jp.debit_amount_minor),0) as d, COALESCE(SUM(jp.credit_amount_minor),0) as c')
        ->first();

    expect((int) $row->d - (int) $row->c)->toBe($balance);
});
