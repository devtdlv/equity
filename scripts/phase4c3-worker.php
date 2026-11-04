<?php

/**
 * Phase 4C.3 concurrency worker — production Equity IMMEDIATE path.
 *
 * Boots Laravel against the runner-approved disposable SQLite file only.
 * Sets equity.sqlite.financial_immediate from EQUITY_FINANCIAL_IMMEDIATE (or payload).
 * Does not register Phase 3 test-only IMMEDIATE resolvers.
 */

declare(strict_types=1);

use App\DataTransferObjects\CreateFinancialTransactionInput;
use App\DataTransferObjects\PostOpeningBalanceInput;
use App\DataTransferObjects\ReverseFinancialTransactionInput;
use App\Database\SQLite\EquitySQLiteConnection;
use App\Database\SQLite\ImmediateAwareSqlitePdo;
use App\Enums\FinancialTransactionType;
use App\Exceptions\FinancialImmediateTransactionConflictException;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\LedgerIntegrityException;
use App\Models\Category;
use App\Models\Currency;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\FinancialAccountService;
use App\Services\FinancialTransactionService;
use App\Services\OpeningBalanceService;
use App\Services\OpeningEquityAccountService;
use App\Support\IdempotencyUniqueConstraint;
use App\Support\TransientTransactionFailure;
use App\Support\TransientTransactionRetryBackoff;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Tests\Integration\Concurrency\Phase3Barrier;
use Tests\Integration\Phase4C3\Phase4C3Isolation;

$repoRoot = dirname(__DIR__);
require $repoRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';

$payloadArg = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--payload=')) {
        $payloadArg = substr($arg, strlen('--payload='));
    }
}

if ($payloadArg === null || $payloadArg === '' || ! is_file($payloadArg)) {
    fwrite(STDERR, "Phase 4C.3 worker: missing payload file.\n");
    exit(2);
}

$raw = file_get_contents($payloadArg);
if ($raw === false) {
    fwrite(STDERR, "Phase 4C.3 worker: could not read payload.\n");
    exit(2);
}

/** @var array<string, mixed> $spec */
$spec = json_decode($raw, true);
if (! is_array($spec)) {
    fwrite(STDERR, "Phase 4C.3 worker: invalid payload JSON.\n");
    exit(2);
}

$resultPath = is_string($spec['result_path'] ?? null) ? $spec['result_path'] : null;
$barrierPath = is_string($spec['barrier_path'] ?? null) ? $spec['barrier_path'] : null;
$action = is_string($spec['action'] ?? null) ? $spec['action'] : null;
$token = is_string($spec['token'] ?? null) ? $spec['token'] : null;
$payload = is_array($spec['payload'] ?? null) ? $spec['payload'] : [];
$startAt = isset($spec['start_at']) && is_numeric($spec['start_at']) ? (float) $spec['start_at'] : null;
$readyKey = is_string($spec['ready_key'] ?? null) ? $spec['ready_key'] : null;
$goPath = is_string($spec['go_path'] ?? null) ? $spec['go_path'] : null;
$awaitGoFile = ($spec['await_go_file'] ?? false) === true;
$payloadImmediate = $spec['financial_immediate'] ?? null;

if ($resultPath === null || $barrierPath === null || $action === null || $token === null || $startAt === null) {
    fwrite(STDERR, "Phase 4C.3 worker: incomplete payload contract.\n");
    exit(2);
}

$envToken = getenv('EQUITY_PHASE4C3_TOKEN') ?: ($_SERVER['EQUITY_PHASE4C3_TOKEN'] ?? '');
if (! is_string($envToken) || $envToken === '' || $envToken !== $token) {
    fwrite(STDERR, "Phase 4C.3 worker: token mismatch; refusing to run.\n");
    exit(2);
}

// Resolve IMMEDIATE flag before boot (payload mirrors env for hosts that drop unfamiliar keys).
$immediate = false;
$envImmediate = getenv('EQUITY_FINANCIAL_IMMEDIATE');
if (is_string($envImmediate) && $envImmediate !== '') {
    $immediate = in_array(strtolower($envImmediate), ['1', 'true', 'yes', 'on'], true);
} elseif ($payloadImmediate !== null) {
    $immediate = filter_var($payloadImmediate, FILTER_VALIDATE_BOOLEAN);
}

/**
 * @param  array<string, mixed>  $result
 */
function phase4c3_worker_write_result(string $resultPath, array $result): void
{
    if (file_exists($resultPath)) {
        throw new RuntimeException('Result path already exists.');
    }
    $json = json_encode($result, JSON_THROW_ON_ERROR);
    $handle = fopen($resultPath, 'x+b');
    if ($handle === false) {
        throw new RuntimeException('Exclusive create failed for result file.');
    }
    fwrite($handle, $json);
    fclose($handle);
}

function phase4c3_worker_wait_until(float $startAt, int $timeoutSeconds = 30): void
{
    $deadline = microtime(true) + $timeoutSeconds;
    while (microtime(true) < $startAt) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Timed out waiting for shared start_at.');
        }
        usleep(1_000);
    }
}

function phase4c3_worker_wait_for_file(string $path, int $timeoutSeconds): void
{
    $deadline = microtime(true) + $timeoutSeconds;
    while (microtime(true) < $deadline) {
        clearstatcache(true, $path);
        if (is_file($path)) {
            return;
        }
        usleep(5_000);
    }

    throw new RuntimeException('Timed out waiting for file: '.$path);
}

/**
 * @return array<string, mixed>
 */
function phase4c3_worker_concurrency_exception_payload(Throwable $e): array
{
    $laravelDeadlockWrapper = $e instanceof DeadlockException;
    $probe = $e;
    if ($laravelDeadlockWrapper && $e->getPrevious() instanceof QueryException) {
        $probe = $e->getPrevious();
    }

    $errorInfo = [];
    if ($probe instanceof QueryException) {
        $errorInfo = $probe->errorInfo ?? [];
    } elseif ($probe instanceof PDOException) {
        $errorInfo = is_array($probe->errorInfo ?? null) ? $probe->errorInfo : [];
    }

    $driverCode = (int) ($errorInfo[1] ?? 0);
    $sqlState = (string) ($errorInfo[0] ?? '');
    $message = $probe->getMessage();
    $isUnique = $probe instanceof UniqueConstraintViolationException
        || ($probe instanceof QueryException && IdempotencyUniqueConstraint::isDuplicateKeyViolation($probe));
    $isLock = in_array($driverCode, [
        TransientTransactionFailure::SQLITE_BUSY,
        TransientTransactionFailure::SQLITE_LOCKED,
    ], true)
        || $laravelDeadlockWrapper
        || str_contains(strtolower($message), 'database is locked')
        || str_contains(strtolower($message), 'sqlite_busy')
        || str_contains(strtolower($message), 'sqlite_locked');

    return [
        'error_class' => $e::class,
        'driver_code' => $driverCode,
        'sqlstate' => $sqlState,
        'error' => $message,
        'is_unique_violation' => $isUnique,
        'is_sqlite_lock' => $isLock,
        'laravel_deadlock_wrapper' => $laravelDeadlockWrapper,
    ];
}

try {
    $barrier = new Phase3Barrier($barrierPath);

    if ($awaitGoFile) {
        if ($readyKey === null || $goPath === null || ! str_contains(basename($goPath), $token)) {
            throw new RuntimeException('Controlled worker requires ready_key and token-scoped go_path.');
        }
        $barrier->set($readyKey, '1');
        phase4c3_worker_wait_for_file($goPath, 60);
    } else {
        phase4c3_worker_wait_until($startAt);
    }

    $derivedRepo = realpath($repoRoot);
    if ($derivedRepo === false) {
        throw new RuntimeException('Phase 4C.3 worker could not resolve repository root.');
    }
    $derivedRepo = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $derivedRepo);

    // Isolation boot validates disposable DB + disposable env file without opening repo .env.
    Phase4C3Isolation::bootFromEnvironment($derivedRepo);

    $app = require $repoRoot.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php';
    Phase4C3Isolation::redirectEnvironmentAwayFromProtectedDotEnv($app);

    $envFile = basename(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $app->environmentFilePath()));
    if ($envFile === '.env' || strcasecmp($envFile, '.env') === 0) {
        throw new RuntimeException('Phase 4C.3 worker refused to bootstrap against a protected .env basename.');
    }

    $app->make(Kernel::class)->bootstrap();

    config(['equity.sqlite.financial_immediate' => $immediate]);

    $connection = DB::connection();
    if (! $connection instanceof EquitySQLiteConnection) {
        throw new RuntimeException('Phase 4C.3 worker requires EquitySQLiteConnection; got '.$connection::class);
    }
    if (! $connection->getPdo() instanceof ImmediateAwareSqlitePdo) {
        throw new RuntimeException('Phase 4C.3 worker requires ImmediateAwareSqlitePdo.');
    }

    $started = microtime(true);
    $outcome = ['ok' => false, 'action' => $action, 'financial_immediate' => $immediate];

    switch ($action) {
        case 'post_expense':
            $user = User::query()->findOrFail((int) $payload['user_id']);
            $service = $app->make(FinancialTransactionService::class);
            // Test-only retry observation via backoff sleeper (no production service changes).
            $retryPauses = 0;
            TransientTransactionRetryBackoff::setSleeper(static function (int $micros) use (&$retryPauses): void {
                $retryPauses++;
                usleep($micros);
            });
            try {
                // Test-only: signal immediately before the financial write so the parent can
                // start the measured hold window after worker boot, not during it.
                $attemptingKey = is_string($payload['attempting_key'] ?? null) ? $payload['attempting_key'] : null;
                if ($attemptingKey !== null && $attemptingKey !== '') {
                    $barrier->set($attemptingKey, '1');
                }
                try {
                    $created = $service->createTransaction(new CreateFinancialTransactionInput(
                        user: $user,
                        type: FinancialTransactionType::Expense,
                        financialAccountId: (int) $payload['financial_account_id'],
                        amount: (string) $payload['amount'],
                        entryDate: (string) $payload['entry_date'],
                        description: (string) $payload['description'],
                        idempotencyKey: (string) $payload['idempotency_key'],
                        categoryId: (int) $payload['category_id'],
                    ));
                    $journal = $created->journalEntry->load('postings.ledgerAccount');
                    $categoryLedgerId = null;
                    foreach ($journal->postings as $posting) {
                        $ledger = $posting->ledgerAccount;
                        if ($ledger instanceof LedgerAccount && $ledger->category_id !== null) {
                            $categoryLedgerId = (int) $ledger->id;
                            break;
                        }
                    }
                    $outcome = [
                        'ok' => true,
                        'action' => $action,
                        'financial_immediate' => $immediate,
                        'was_recently_created' => $created->wasRecentlyCreated,
                        'transaction_id' => $created->transaction->id,
                        'journal_entry_id' => $journal->id,
                        'amount_minor' => $created->transaction->amount_minor,
                        'debit_sum' => (int) $journal->postings->sum('debit_amount_minor'),
                        'credit_sum' => (int) $journal->postings->sum('credit_amount_minor'),
                        'posting_count' => $journal->postings->count(),
                        'category_ledger_account_id' => $categoryLedgerId,
                        'txn_level_after' => DB::transactionLevel(),
                        'retry_pauses' => $retryPauses,
                        'attempts' => $retryPauses + 1,
                    ];
                } catch (IdempotencyConflictException $e) {
                    $outcome = [
                        'ok' => false,
                        'action' => $action,
                        'financial_immediate' => $immediate,
                        'classification' => 'idempotency_conflict',
                        'error_class' => IdempotencyConflictException::class,
                        'error' => $e->getMessage(),
                        'txn_level_after' => DB::transactionLevel(),
                        'retry_pauses' => $retryPauses,
                        'attempts' => $retryPauses + 1,
                    ];
                } catch (DeadlockException|QueryException $e) {
                    $outcome = array_merge(
                        [
                            'ok' => false,
                            'action' => $action,
                            'financial_immediate' => $immediate,
                            'classification' => 'sqlite_lock_or_query',
                            'txn_level_after' => DB::transactionLevel(),
                            'retry_pauses' => $retryPauses,
                            'attempts' => $retryPauses + 1,
                        ],
                        phase4c3_worker_concurrency_exception_payload($e),
                    );
                }
            } finally {
                TransientTransactionRetryBackoff::resetSleeper();
            }
            break;

        case 'reverse_transaction':
            $user = User::query()->findOrFail((int) $payload['user_id']);
            $service = $app->make(FinancialTransactionService::class);
            try {
                $reversed = $service->reverseTransaction(new ReverseFinancialTransactionInput(
                    user: $user,
                    originalTransactionId: (int) $payload['original_transaction_id'],
                    idempotencyKey: (string) $payload['idempotency_key'],
                    reason: isset($payload['reason']) ? (string) $payload['reason'] : null,
                ));
                $journal = $reversed->reversalJournalEntry->load('postings');
                $outcome = [
                    'ok' => true,
                    'action' => $action,
                    'financial_immediate' => $immediate,
                    'was_recently_created' => $reversed->wasRecentlyCreated,
                    'reversal_transaction_id' => $reversed->reversalTransaction->id,
                    'original_transaction_id' => $reversed->originalTransaction->id,
                    'journal_entry_id' => $journal->id,
                    'debit_sum' => (int) $journal->postings->sum('debit_amount_minor'),
                    'credit_sum' => (int) $journal->postings->sum('credit_amount_minor'),
                    'txn_level_after' => DB::transactionLevel(),
                ];
            } catch (IdempotencyConflictException $e) {
                $outcome = [
                    'ok' => false,
                    'action' => $action,
                    'financial_immediate' => $immediate,
                    'classification' => 'idempotency_conflict',
                    'error_class' => IdempotencyConflictException::class,
                    'error' => $e->getMessage(),
                    'txn_level_after' => DB::transactionLevel(),
                ];
            } catch (LedgerIntegrityException $e) {
                $message = $e->getMessage();
                $alreadyReversed = $message === 'This financial transaction has already been reversed.';
                $outcome = [
                    'ok' => false,
                    'action' => $action,
                    'financial_immediate' => $immediate,
                    'classification' => $alreadyReversed ? 'already_reversed' : 'ledger_integrity',
                    'error_class' => LedgerIntegrityException::class,
                    'error' => $message,
                    'already_reversed' => $alreadyReversed,
                    'txn_level_after' => DB::transactionLevel(),
                ];
            } catch (DeadlockException|QueryException $e) {
                $outcome = array_merge(
                    [
                        'ok' => false,
                        'action' => $action,
                        'financial_immediate' => $immediate,
                        'classification' => 'sqlite_lock_or_query',
                        'txn_level_after' => DB::transactionLevel(),
                    ],
                    phase4c3_worker_concurrency_exception_payload($e),
                );
            }
            break;

        case 'create_account':
            $user = User::query()->findOrFail((int) $payload['user_id']);
            $service = $app->make(FinancialAccountService::class);
            try {
                $created = $service->createAccountResult($user, [
                    'name' => (string) $payload['name'],
                    'type' => (string) $payload['type'],
                    'currency_code' => (string) $payload['currency_code'],
                    'starting_balance' => $payload['starting_balance'] ?? null,
                    'idempotency_key' => (string) $payload['idempotency_key'],
                ]);
                $outcome = [
                    'ok' => true,
                    'action' => $action,
                    'financial_immediate' => $immediate,
                    'was_recently_created' => $created->wasRecentlyCreated,
                    'account_id' => $created->account->id,
                    'opening_journal_entry_id' => $created->account->opening_journal_entry_id,
                    'txn_level_after' => DB::transactionLevel(),
                ];
            } catch (IdempotencyConflictException $e) {
                $outcome = [
                    'ok' => false,
                    'action' => $action,
                    'financial_immediate' => $immediate,
                    'classification' => 'idempotency_conflict',
                    'error_class' => IdempotencyConflictException::class,
                    'error' => $e->getMessage(),
                    'txn_level_after' => DB::transactionLevel(),
                ];
            } catch (DeadlockException|QueryException $e) {
                $outcome = array_merge(
                    [
                        'ok' => false,
                        'action' => $action,
                        'financial_immediate' => $immediate,
                        'classification' => 'sqlite_lock_or_query',
                        'txn_level_after' => DB::transactionLevel(),
                    ],
                    phase4c3_worker_concurrency_exception_payload($e),
                );
            }
            break;

        case 'resolve_category_ledger':
            $user = User::query()->findOrFail((int) $payload['user_id']);
            $category = Category::query()->findOrFail((int) $payload['category_id']);
            $service = $app->make(FinancialTransactionService::class);
            try {
                $ledger = $service->resolveOrCreateCategoryLedgerAccount(
                    $user,
                    $category,
                    (string) $payload['currency_code'],
                );
                $outcome = [
                    'ok' => true,
                    'action' => $action,
                    'financial_immediate' => $immediate,
                    'ledger_account_id' => $ledger->id,
                    'category_id' => (int) $ledger->category_id,
                    'currency_code' => $ledger->currency_code,
                    'txn_level_after' => DB::transactionLevel(),
                ];
            } catch (FinancialImmediateTransactionConflictException $e) {
                $outcome = [
                    'ok' => false,
                    'action' => $action,
                    'financial_immediate' => $immediate,
                    'classification' => 'immediate_conflict',
                    'error_class' => FinancialImmediateTransactionConflictException::class,
                    'error' => $e->getMessage(),
                    'txn_level_after' => DB::transactionLevel(),
                ];
            } catch (DeadlockException|QueryException $e) {
                $outcome = array_merge(
                    [
                        'ok' => false,
                        'action' => $action,
                        'financial_immediate' => $immediate,
                        'classification' => 'sqlite_lock_or_query',
                        'txn_level_after' => DB::transactionLevel(),
                    ],
                    phase4c3_worker_concurrency_exception_payload($e),
                );
            }
            break;

        case 'post_opening_balance':
            $user = User::query()->findOrFail((int) $payload['user_id']);
            $service = $app->make(OpeningBalanceService::class);
            try {
                $posted = $service->postForAccount(new PostOpeningBalanceInput(
                    user: $user,
                    financialAccountId: (int) $payload['financial_account_id'],
                    amountMinor: (int) $payload['amount_minor'],
                    entryDate: (string) $payload['entry_date'],
                    idempotencyKey: (string) $payload['idempotency_key'],
                ));
                $outcome = [
                    'ok' => true,
                    'action' => $action,
                    'financial_immediate' => $immediate,
                    'was_recently_created' => $posted->wasRecentlyCreated,
                    'is_no_op' => $posted->isNoOp,
                    'journal_entry_id' => $posted->journalEntry?->id,
                    'amount_minor' => $posted->amountMinor,
                    'txn_level_after' => DB::transactionLevel(),
                ];
            } catch (IdempotencyConflictException|LedgerIntegrityException $e) {
                $outcome = [
                    'ok' => false,
                    'action' => $action,
                    'financial_immediate' => $immediate,
                    'classification' => 'domain_conflict',
                    'error_class' => $e::class,
                    'error' => $e->getMessage(),
                    'txn_level_after' => DB::transactionLevel(),
                ];
            } catch (DeadlockException|QueryException $e) {
                $outcome = array_merge(
                    [
                        'ok' => false,
                        'action' => $action,
                        'financial_immediate' => $immediate,
                        'classification' => 'sqlite_lock_or_query',
                        'txn_level_after' => DB::transactionLevel(),
                    ],
                    phase4c3_worker_concurrency_exception_payload($e),
                );
            }
            break;

        case 'resolve_opening_equity':
            $user = User::query()->findOrFail((int) $payload['user_id']);
            $currency = Currency::query()->where('code', (string) $payload['currency_code'])->firstOrFail();
            $service = $app->make(OpeningEquityAccountService::class);
            try {
                $ledger = $service->resolveOrCreate($user, $currency);
                $outcome = [
                    'ok' => true,
                    'action' => $action,
                    'financial_immediate' => $immediate,
                    'ledger_account_id' => $ledger->id,
                    'code' => $ledger->code,
                    'txn_level_after' => DB::transactionLevel(),
                ];
            } catch (DeadlockException|QueryException $e) {
                $outcome = array_merge(
                    [
                        'ok' => false,
                        'action' => $action,
                        'financial_immediate' => $immediate,
                        'classification' => 'sqlite_lock_or_query',
                        'txn_level_after' => DB::transactionLevel(),
                    ],
                    phase4c3_worker_concurrency_exception_payload($e),
                );
            }
            break;

        case 'hold_immediate_writer':
            $heldKey = is_string($payload['held_key'] ?? null) ? $payload['held_key'] : null;
            $releaseKey = is_string($payload['release_key'] ?? null) ? $payload['release_key'] : null;
            // Prefer runner-approved env path; Isolation statics are PHPUnit-boot only.
            $dbPath = getenv('EQUITY_PHASE4C3_SQLITE_PATH') ?: ($_SERVER['EQUITY_PHASE4C3_SQLITE_PATH'] ?? null);
            if (! is_string($dbPath) || $dbPath === '' || ! is_file($dbPath)) {
                $dbPath = (string) config('database.connections.sqlite.database');
            }
            if ($dbPath === '' || ! is_file($dbPath)) {
                throw new RuntimeException('hold_immediate_writer could not resolve disposable SQLite path.');
            }
            $pdo = new ImmediateAwareSqlitePdo('sqlite:'.$dbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->setPendingBeginMode(ImmediateAwareSqlitePdo::MODE_IMMEDIATE);
            $pdo->beginTransaction();
            try {
                $pdo->exec("UPDATE currencies SET name = name WHERE code = 'PHP'");
                if ($heldKey !== null && $releaseKey !== null) {
                    $barrier->set($heldKey, '1');
                    $barrier->waitFor($releaseKey, '1', 40);
                }
                $pdo->commit();
                $outcome = [
                    'ok' => true,
                    'action' => $action,
                    'financial_immediate' => $immediate,
                    'held' => true,
                ];
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            } finally {
                $pdo = null;
            }
            break;

        default:
            throw new RuntimeException('Unknown Phase 4C.3 worker action: '.$action);
    }

    $outcome['elapsed_ms'] = (int) round((microtime(true) - $started) * 1000);
    phase4c3_worker_write_result($resultPath, $outcome);
    exit($outcome['ok'] ? 0 : 1);
} catch (Throwable $e) {
    try {
        phase4c3_worker_write_result($resultPath ?? (sys_get_temp_dir().DIRECTORY_SEPARATOR.'phase4c3-orphan-'.bin2hex(random_bytes(4)).'.json'), [
            'ok' => false,
            'action' => $action ?? 'unknown',
            'classification' => 'worker_crash',
            'error_class' => $e::class,
            'error' => $e->getMessage(),
            'txn_level_after' => 0,
        ]);
    } catch (Throwable) {
        // ignore secondary write failures
    }
    fwrite(STDERR, 'Phase 4C.3 worker failure: '.$e->getMessage()."\n");
    exit(2);
}
