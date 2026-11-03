<?php

/**
 * Phase 3 concurrency worker.
 *
 * Boots Laravel against the runner-approved disposable SQLite file only.
 * Wave workers synchronize on a shared start_at timestamp.
 * Controlled single workers may also await an exclusive go file after signaling ready.
 */

declare(strict_types=1);

use App\DataTransferObjects\CreateFinancialTransactionInput;
use App\DataTransferObjects\ReverseFinancialTransactionInput;
use App\Enums\FinancialTransactionType;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\LedgerIntegrityException;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\FinancialAccountService;
use App\Services\FinancialTransactionService;
use App\Support\IdempotencyUniqueConstraint;
use App\Support\TransientTransactionFailure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\Integration\Concurrency\Phase3Barrier;
use Tests\Integration\Concurrency\Phase3Isolation;

$repoRoot = dirname(__DIR__);
require $repoRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';

$payloadArg = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--payload=')) {
        $payloadArg = substr($arg, strlen('--payload='));
    }
}

if ($payloadArg === null || $payloadArg === '' || ! is_file($payloadArg)) {
    fwrite(STDERR, "Phase 3 worker: missing payload file.\n");
    exit(2);
}

$raw = file_get_contents($payloadArg);
if ($raw === false) {
    fwrite(STDERR, "Phase 3 worker: could not read payload.\n");
    exit(2);
}

/** @var array<string, mixed> $spec */
$spec = json_decode($raw, true);
if (! is_array($spec)) {
    fwrite(STDERR, "Phase 3 worker: invalid payload JSON.\n");
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

if ($resultPath === null || $barrierPath === null || $action === null || $token === null || $startAt === null) {
    fwrite(STDERR, "Phase 3 worker: incomplete payload contract.\n");
    exit(2);
}

$envToken = getenv('EQUITY_PHASE3_TOKEN') ?: ($_SERVER['EQUITY_PHASE3_TOKEN'] ?? '');
if (! is_string($envToken) || $envToken === '' || $envToken !== $token) {
    fwrite(STDERR, "Phase 3 worker: token mismatch; refusing to run.\n");
    exit(2);
}

/**
 * @param  array<string, mixed>  $result
 */
function phase3_worker_write_result(string $resultPath, array $result): void
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

function phase3_worker_wait_until(float $startAt, int $timeoutSeconds = 30): void
{
    $deadline = microtime(true) + $timeoutSeconds;
    while (microtime(true) < $startAt) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Timed out waiting for shared start_at.');
        }
        usleep(1_000);
    }
}

function phase3_worker_wait_for_file(string $path, int $timeoutSeconds): void
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
 * Extended SQLite result codes are not reliably exposed by PHP PDO.
 * Only return a value when the driver/message actually carries it — never infer
 * SQLITE_BUSY_SNAPSHOT (517) from primary SQLITE_BUSY (5).
 */
function phase3_worker_extended_driver_code_from_parts(int $driverCode, string $message): ?int
{
    if ($driverCode > 255) {
        return $driverCode;
    }

    if (preg_match('/\b(5(?:16|17)|261)\b/', $message, $matches) === 1) {
        return (int) $matches[1];
    }

    return null;
}

/**
 * Laravel may wrap nested-transaction SQLITE_BUSY as DeadlockException (PDOException,
 * not QueryException). Prefer the previous QueryException's errorInfo when present.
 *
 * @return array{
 *   error_class: string,
 *   driver_code: int,
 *   extended_driver_code: int|null,
 *   sqlstate: string,
 *   error: string,
 *   is_unique_violation: bool,
 *   is_sqlite_lock: bool,
 *   laravel_deadlock_wrapper: bool
 * }
 */
function phase3_worker_concurrency_exception_payload(Throwable $e): array
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
        'extended_driver_code' => phase3_worker_extended_driver_code_from_parts($driverCode, $message),
        'sqlstate' => $sqlState,
        'error' => $message,
        'is_unique_violation' => $isUnique,
        'is_sqlite_lock' => $isLock,
        'laravel_deadlock_wrapper' => $laravelDeadlockWrapper,
    ];
}

/**
 * @return array<string, mixed>
 */
function phase3_worker_query_exception_payload(QueryException $e): array
{
    return phase3_worker_concurrency_exception_payload($e);
}

try {
    Phase3Isolation::bootFromEnvironment($repoRoot);
    if (Phase3Isolation::runToken() !== $token) {
        fwrite(STDERR, "Phase 3 worker: isolation token mismatch.\n");
        exit(2);
    }

    $barrier = new Phase3Barrier($barrierPath);

    if ($awaitGoFile) {
        if ($readyKey === null || $goPath === null || ! str_contains(basename($goPath), $token)) {
            throw new RuntimeException('Controlled worker requires ready_key and token-scoped go_path.');
        }
        $barrier->set($readyKey, '1');
        phase3_worker_wait_for_file($goPath, 60);
    } else {
        phase3_worker_wait_until($startAt);
    }

    $app = require $repoRoot.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php';
    $app->make(Kernel::class)->bootstrap();

    $started = microtime(true);
    $outcome = ['ok' => false, 'action' => $action];

    switch ($action) {
        case 'post_expense':
            $user = User::query()->findOrFail((int) $payload['user_id']);
            $service = $app->make(FinancialTransactionService::class);
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
                    'was_recently_created' => $created->wasRecentlyCreated,
                    'transaction_id' => $created->transaction->id,
                    'journal_entry_id' => $journal->id,
                    'amount_minor' => $created->transaction->amount_minor,
                    'debit_sum' => (int) $journal->postings->sum('debit_amount_minor'),
                    'credit_sum' => (int) $journal->postings->sum('credit_amount_minor'),
                    'posting_count' => $journal->postings->count(),
                    'category_ledger_account_id' => $categoryLedgerId,
                    'financial_account_id' => (int) $created->transaction->financial_account_id,
                ];
            } catch (IdempotencyConflictException $e) {
                $outcome = [
                    'ok' => false,
                    'action' => $action,
                    'error_class' => IdempotencyConflictException::class,
                    'error' => $e->getMessage(),
                ];
            } catch (DeadlockException|QueryException $e) {
                $outcome = array_merge(
                    ['ok' => false, 'action' => $action],
                    phase3_worker_concurrency_exception_payload($e),
                );
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
                    'was_recently_created' => $reversed->wasRecentlyCreated,
                    'reversal_transaction_id' => $reversed->reversalTransaction->id,
                    'original_transaction_id' => $reversed->originalTransaction->id,
                    'journal_entry_id' => $journal->id,
                    'debit_sum' => (int) $journal->postings->sum('debit_amount_minor'),
                    'credit_sum' => (int) $journal->postings->sum('credit_amount_minor'),
                    'posting_count' => $journal->postings->count(),
                ];
            } catch (IdempotencyConflictException $e) {
                $outcome = [
                    'ok' => false,
                    'action' => $action,
                    'error_class' => IdempotencyConflictException::class,
                    'error' => $e->getMessage(),
                ];
            } catch (LedgerIntegrityException $e) {
                $message = $e->getMessage();
                $alreadyReversed = $message === 'This financial transaction has already been reversed.';
                $outcome = [
                    'ok' => false,
                    'action' => $action,
                    'error_class' => LedgerIntegrityException::class,
                    'error' => $message,
                    // Structured flag for the exact production already-reversed message only.
                    'already_reversed' => $alreadyReversed,
                ];
            } catch (DeadlockException|QueryException $e) {
                $outcome = array_merge(
                    ['ok' => false, 'action' => $action],
                    phase3_worker_concurrency_exception_payload($e),
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
                    'was_recently_created' => $created->wasRecentlyCreated,
                    'account_id' => $created->account->id,
                    'opening_journal_entry_id' => $created->account->opening_journal_entry_id,
                ];
            } catch (IdempotencyConflictException $e) {
                $outcome = [
                    'ok' => false,
                    'action' => $action,
                    'error_class' => IdempotencyConflictException::class,
                    'error' => $e->getMessage(),
                ];
            } catch (DeadlockException|QueryException $e) {
                $outcome = array_merge(
                    ['ok' => false, 'action' => $action],
                    phase3_worker_concurrency_exception_payload($e),
                );
            }
            break;

        case 'hold_write_then_release':
            // Prefer explicit held/release barrier keys so the parent can start a
            // contender only after this worker owns the SQLite write lock.
            $heldKey = is_string($payload['held_key'] ?? null) ? $payload['held_key'] : null;
            $releaseKey = is_string($payload['release_key'] ?? null) ? $payload['release_key'] : null;
            $holdMs = (int) ($payload['hold_ms'] ?? 1500);
            DB::beginTransaction();
            try {
                DB::table('currencies')->where('code', 'PHP')->update(['name' => DB::raw('name')]);
                if ($heldKey !== null && $releaseKey !== null) {
                    $barrier->set($heldKey, '1');
                    $barrier->waitFor($releaseKey, '1', 40);
                } else {
                    usleep(max(0, $holdMs) * 1000);
                }
                DB::commit();
                $outcome = ['ok' => true, 'action' => $action, 'held_ms' => $holdMs];
            } catch (Throwable $e) {
                DB::rollBack();
                throw $e;
            }
            break;

        case 'contended_write':
            $enteringKey = is_string($payload['entering_key'] ?? null) ? $payload['entering_key'] : null;
            try {
                DB::beginTransaction();
                // Signal that the write attempt is imminent (separate barrier DB), then
                // perform the UPDATE that contends for the application write lock.
                if ($enteringKey !== null) {
                    $barrier->set($enteringKey, '1');
                }
                $waitStarted = microtime(true);
                DB::table('currencies')->where('code', 'PHP')->update(['name' => DB::raw('name')]);
                DB::commit();
                $outcome = [
                    'ok' => true,
                    'action' => $action,
                    'wait_ms' => (int) round((microtime(true) - $waitStarted) * 1000),
                ];
            } catch (QueryException $e) {
                try {
                    DB::rollBack();
                } catch (Throwable) {
                }
                $outcome = array_merge(
                    [
                        'ok' => false,
                        'action' => $action,
                        'wait_ms' => (int) round((microtime(true) - ($waitStarted ?? microtime(true))) * 1000),
                    ],
                    phase3_worker_query_exception_payload($e),
                );
            }
            break;

        case 'read_currency_name':
            $row = DB::table('currencies')->where('code', 'PHP')->first();
            $outcome = [
                'ok' => true,
                'action' => $action,
                'name' => is_object($row) ? (string) ($row->name ?? '') : '',
                'journal_mode' => strtolower((string) (DB::selectOne('PRAGMA journal_mode')->journal_mode ?? '')),
                'foreign_keys' => (int) (DB::selectOne('PRAGMA foreign_keys')->foreign_keys ?? 0),
            ];
            break;

        case 'hold_uncommitted_currency_rename':
            $newName = (string) ($payload['new_name'] ?? 'PHP-HELD');
            $heldKey = is_string($payload['held_key'] ?? null) ? $payload['held_key'] : null;
            $releaseKey = is_string($payload['release_key'] ?? null) ? $payload['release_key'] : null;
            if ($heldKey === null || $releaseKey === null) {
                throw new RuntimeException('held_key and release_key required');
            }
            DB::beginTransaction();
            DB::table('currencies')->where('code', 'PHP')->update(['name' => $newName]);
            $barrier->set($heldKey, '1');
            // Fresh PDO per get() in Phase3Barrier so parent→worker release is visible.
            $barrier->waitFor($releaseKey, '1', 40);
            DB::commit();
            $outcome = ['ok' => true, 'action' => $action, 'committed_name' => $newName];
            break;

        case 'open_txn_and_wait_forever':
            $heldKey = is_string($payload['held_key'] ?? null) ? $payload['held_key'] : null;
            if ($heldKey === null) {
                throw new RuntimeException('held_key required');
            }
            DB::beginTransaction();
            DB::table('currencies')->where('code', 'PHP')->update(['name' => 'PHP-INTERRUPTED']);
            $barrier->set($heldKey, '1');
            while (true) {
                usleep(100_000);
            }

        /*
         | Diagnostic-only actions (Phase 4B.3). Currency no-op / temporary rename only.
         | Not used by production financial paths.
         */
        case 'diag_snapshot_writer':
            $readKey = is_string($payload['read_key'] ?? null) ? $payload['read_key'] : null;
            $peerReadKey = is_string($payload['peer_read_key'] ?? null) ? $payload['peer_read_key'] : null;
            $committedKey = is_string($payload['committed_key'] ?? null) ? $payload['committed_key'] : null;
            if ($readKey === null || $peerReadKey === null || $committedKey === null) {
                throw new RuntimeException('diag_snapshot_writer requires read_key, peer_read_key, committed_key');
            }
            $suffix = is_string($payload['name_suffix'] ?? null) ? $payload['name_suffix'] : 'W';
            DB::beginTransaction();
            $beforeName = (string) DB::table('currencies')->where('code', 'PHP')->value('name');
            $barrier->set($readKey, '1');
            $barrier->waitFor($peerReadKey, '1', 30);
            $writeStarted = microtime(true);
            // Must change a value so SQLite performs a real write upgrade.
            DB::table('currencies')->where('code', 'PHP')->update([
                'name' => 'PHP-DIAG-'.$suffix,
            ]);
            $writeMs = (int) round((microtime(true) - $writeStarted) * 1000);
            DB::commit();
            $barrier->set($committedKey, '1');
            $outcome = [
                'ok' => true,
                'action' => $action,
                'diag' => [
                    'role' => 'snapshot_writer',
                    'failure_phase' => null,
                    'first_write_after_reads' => true,
                    'write_attempt_ms' => $writeMs,
                    'prior_name' => $beforeName,
                ],
            ];
            break;

        case 'diag_snapshot_contender':
            $readKey = is_string($payload['read_key'] ?? null) ? $payload['read_key'] : null;
            $peerReadKey = is_string($payload['peer_read_key'] ?? null) ? $payload['peer_read_key'] : null;
            $committedKey = is_string($payload['committed_key'] ?? null) ? $payload['committed_key'] : null;
            $suffix = is_string($payload['name_suffix'] ?? null) ? $payload['name_suffix'] : 'C';
            if ($readKey === null || $peerReadKey === null || $committedKey === null) {
                throw new RuntimeException('diag_snapshot_contender requires read_key, peer_read_key, committed_key');
            }
            DB::beginTransaction();
            $beforeName = (string) DB::table('currencies')->where('code', 'PHP')->value('name');
            $barrier->set($readKey, '1');
            $barrier->waitFor($peerReadKey, '1', 30);
            // Peer must commit while this deferred txn still holds its read snapshot.
            $barrier->waitFor($committedKey, '1', 30);
            $writeStarted = microtime(true);
            try {
                DB::table('currencies')->where('code', 'PHP')->update([
                    'name' => 'PHP-DIAG-'.$suffix,
                ]);
                DB::commit();
                $outcome = [
                    'ok' => true,
                    'action' => $action,
                    'diag' => [
                        'role' => 'snapshot_contender',
                        'failure_phase' => null,
                        'first_write_after_reads' => true,
                        'write_attempt_ms' => (int) round((microtime(true) - $writeStarted) * 1000),
                        'prior_name' => $beforeName,
                        'note' => 'unexpected_success_after_peer_commit',
                    ],
                ];
            } catch (QueryException $e) {
                $writeMs = (int) round((microtime(true) - $writeStarted) * 1000);
                try {
                    DB::rollBack();
                } catch (Throwable) {
                }
                $outcome = array_merge(
                    [
                        'ok' => false,
                        'action' => $action,
                        'diag' => [
                            'role' => 'snapshot_contender',
                            'failure_phase' => 'write_after_peer_commit',
                            'first_write_after_reads' => true,
                            'write_attempt_ms' => $writeMs,
                        ],
                    ],
                    phase3_worker_query_exception_payload($e),
                );
            }
            break;

        case 'diag_held_writer':
            $heldKey = is_string($payload['held_key'] ?? null) ? $payload['held_key'] : null;
            $releaseKey = is_string($payload['release_key'] ?? null) ? $payload['release_key'] : null;
            if ($heldKey === null || $releaseKey === null) {
                throw new RuntimeException('diag_held_writer requires held_key, release_key');
            }
            DB::beginTransaction();
            $writeStarted = microtime(true);
            DB::table('currencies')->where('code', 'PHP')->update(['name' => DB::raw('name')]);
            $writeMs = (int) round((microtime(true) - $writeStarted) * 1000);
            $barrier->set($heldKey, '1');
            $barrier->waitFor($releaseKey, '1', 40);
            DB::commit();
            $outcome = [
                'ok' => true,
                'action' => $action,
                'diag' => [
                    'role' => 'held_writer',
                    'failure_phase' => null,
                    'first_write_after_reads' => false,
                    'write_attempt_ms' => $writeMs,
                ],
            ];
            break;

        case 'diag_contend_held_write':
            // First statement after BEGIN is a write so SQLite starts a write txn
            // (busy_timeout can wait). Signals entering before the blocking UPDATE.
            $heldKey = is_string($payload['held_key'] ?? null) ? $payload['held_key'] : null;
            $enteringKey = is_string($payload['entering_key'] ?? null) ? $payload['entering_key'] : null;
            if ($heldKey === null || $enteringKey === null) {
                throw new RuntimeException('diag_contend_held_write requires held_key, entering_key');
            }
            $barrier->waitFor($heldKey, '1', 30);
            DB::beginTransaction();
            $barrier->set($enteringKey, '1');
            $writeStarted = microtime(true);
            try {
                DB::table('currencies')->where('code', 'PHP')->update(['name' => DB::raw('name')]);
                DB::commit();
                $outcome = [
                    'ok' => true,
                    'action' => $action,
                    'diag' => [
                        'role' => 'held_contender',
                        'failure_phase' => null,
                        'first_write_after_reads' => false,
                        'write_attempt_ms' => (int) round((microtime(true) - $writeStarted) * 1000),
                    ],
                ];
            } catch (QueryException $e) {
                $writeMs = (int) round((microtime(true) - $writeStarted) * 1000);
                try {
                    DB::rollBack();
                } catch (Throwable) {
                }
                $outcome = array_merge(
                    [
                        'ok' => false,
                        'action' => $action,
                        'diag' => [
                            'role' => 'held_contender',
                            'failure_phase' => 'write_while_peer_holds',
                            'first_write_after_reads' => false,
                            'write_attempt_ms' => $writeMs,
                        ],
                    ],
                    phase3_worker_query_exception_payload($e),
                );
            }
            break;

        default:
            throw new RuntimeException('Unknown Phase 3 worker action: '.$action);
    }

    $outcome['elapsed_ms'] = (int) round((microtime(true) - $started) * 1000);
    $outcome['diag'] = array_merge([
        'elapsed_ms' => $outcome['elapsed_ms'],
        'final_ok' => (bool) ($outcome['ok'] ?? false),
    ], is_array($outcome['diag'] ?? null) ? $outcome['diag'] : []);
    phase3_worker_write_result($resultPath, $outcome);
    exit($outcome['ok'] ? 0 : 1);
} catch (Throwable $e) {
    try {
        $failed = [
            'ok' => false,
            'action' => $action,
            'error_class' => $e::class,
            'error' => $e->getMessage(),
            'elapsed_ms' => isset($started)
                ? (int) round((microtime(true) - $started) * 1000)
                : null,
        ];
        if ($e instanceof DeadlockException || $e instanceof QueryException) {
            $failed = array_merge($failed, phase3_worker_concurrency_exception_payload($e));
        }
        $failed['diag'] = [
            'elapsed_ms' => $failed['elapsed_ms'],
            'final_ok' => false,
            'uncaught' => true,
        ];
        phase3_worker_write_result($resultPath, $failed);
    } catch (Throwable) {
        fwrite(STDERR, $e->getMessage()."\n");
    }
    exit(1);
}
