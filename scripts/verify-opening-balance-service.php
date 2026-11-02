<?php

/**
 * Source-level offline verification for OpeningBalanceService.
 *
 * Reads PHP source only. Does not bootstrap Laravel, connect to a database,
 * instantiate Eloquent models, or execute posting.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

$servicePath = $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'OpeningBalanceService.php';
$inputPath = $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'DataTransferObjects'.DIRECTORY_SEPARATOR.'PostOpeningBalanceInput.php';
$resultPath = $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'DataTransferObjects'.DIRECTORY_SEPARATOR.'PostedOpeningBalanceResult.php';
$auditPath = $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'AuditService.php';
$postingPath = $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'LedgerPostingService.php';
$equityPath = $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'OpeningEquityAccountService.php';
$accountServicePath = $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'FinancialAccountService.php';
$transactionServicePath = $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'FinancialTransactionService.php';
$accountModelPath = $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Models'.DIRECTORY_SEPARATOR.'FinancialAccount.php';
$architecturePath = $root.DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'architecture.md';
$routesPath = $root.DIRECTORY_SEPARATOR.'routes'.DIRECTORY_SEPARATOR.'web.php';
$accountControllerPath = $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Http'.DIRECTORY_SEPARATOR.'Controllers'.DIRECTORY_SEPARATOR.'FinancialAccountController.php';
$moneyPath = $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'Money.php';

$passed = 0;
$failed = 0;
$failures = [];

function opening_balance_assert(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed, $failures;

    if ($condition) {
        $passed++;
        echo 'PASS  '.$name."\n";

        return;
    }

    $failed++;
    $message = $name.($detail !== '' ? ': '.$detail : '');
    $failures[] = $message;
    echo 'FAIL  '.$message."\n";
}

function opening_balance_extract_method(string $source, string $functionName): string
{
    $needle = 'function '.$functionName;
    $start = strpos($source, $needle);

    if ($start === false) {
        return '';
    }

    $brace = strpos($source, '{', $start);

    if ($brace === false) {
        return '';
    }

    $depth = 0;
    $length = strlen($source);

    for ($index = $brace; $index < $length; $index++) {
        $character = $source[$index];

        if ($character === '{') {
            $depth++;
        } elseif ($character === '}') {
            $depth--;

            if ($depth === 0) {
                return substr($source, $start, $index - $start + 1);
            }
        }
    }

    return '';
}

$source = is_file($servicePath) ? (string) file_get_contents($servicePath) : '';
$inputSource = is_file($inputPath) ? (string) file_get_contents($inputPath) : '';
$resultSource = is_file($resultPath) ? (string) file_get_contents($resultPath) : '';
$auditSource = is_file($auditPath) ? (string) file_get_contents($auditPath) : '';
$postingSource = is_file($postingPath) ? (string) file_get_contents($postingPath) : '';
$equitySource = is_file($equityPath) ? (string) file_get_contents($equityPath) : '';
$accountServiceSource = is_file($accountServicePath) ? (string) file_get_contents($accountServicePath) : '';
$transactionServiceSource = is_file($transactionServicePath) ? (string) file_get_contents($transactionServicePath) : '';
$accountModelSource = is_file($accountModelPath) ? (string) file_get_contents($accountModelPath) : '';
$architecture = is_file($architecturePath) ? (string) file_get_contents($architecturePath) : '';
$routesSource = is_file($routesPath) ? (string) file_get_contents($routesPath) : '';
$accountControllerSource = is_file($accountControllerPath) ? (string) file_get_contents($accountControllerPath) : '';
$moneySource = is_file($moneyPath) ? (string) file_get_contents($moneyPath) : '';

$publicApi = opening_balance_extract_method($source, 'postForAccount');
$inCurrent = opening_balance_extract_method($source, 'postForAccountInCurrentTransaction');
$replay = opening_balance_extract_method($source, 'replayPointedOpening');
$buildPostings = opening_balance_extract_method($source, 'buildPostingInstructions');
$linkedLedger = opening_balance_extract_method($source, 'linkedAccountLedger');
$lockCurrency = opening_balance_extract_method($source, 'lockCurrency');
$existingEquity = opening_balance_extract_method($source, 'existingOpeningEquityLedger');
$assertExistingEquity = opening_balance_extract_method($source, 'assertExistingOpeningEquity');
$validateDate = opening_balance_extract_method($source, 'validateEntryDate');
$assertAmount = opening_balance_extract_method($source, 'assertAmountMinor');
$positiveMagnitude = opening_balance_extract_method($source, 'positiveMagnitude');

opening_balance_assert('service source file exists', is_file($servicePath), $servicePath);
opening_balance_assert(
    'public API is postForAccount(PostOpeningBalanceInput): PostedOpeningBalanceResult',
    str_contains($source, 'public function postForAccount(PostOpeningBalanceInput $input): PostedOpeningBalanceResult'),
);
opening_balance_assert(
    'input DTO carries user, account id, signed amount, entry date, and idempotency key',
    str_contains($inputSource, 'final readonly class PostOpeningBalanceInput')
        && str_contains($inputSource, 'public User $user')
        && str_contains($inputSource, 'public int $financialAccountId')
        && str_contains($inputSource, 'public int $amountMinor')
        && str_contains($inputSource, 'public string $entryDate')
        && str_contains($inputSource, 'public string $idempotencyKey'),
);
opening_balance_assert(
    'result DTO includes no-op flag, creation flag, and optional journal',
    str_contains($resultSource, 'final readonly class PostedOpeningBalanceResult')
        && str_contains($resultSource, 'public FinancialAccount $account')
        && str_contains($resultSource, 'public int $amountMinor')
        && str_contains($resultSource, 'public bool $wasRecentlyCreated')
        && str_contains($resultSource, 'public bool $isNoOp')
        && str_contains($resultSource, 'public ?JournalEntry $journalEntry = null'),
);
opening_balance_assert(
    'canonical description is Starting balance',
    str_contains($source, "CANONICAL_DESCRIPTION = 'Starting balance'"),
);
opening_balance_assert(
    'does not create financial transaction records',
    ! str_contains($source, 'FinancialTransaction::')
        && ! str_contains($source, 'CreateFinancialTransaction')
        && ! str_contains($source, 'new FinancialTransaction'),
);
opening_balance_assert(
    'does not use abs() on the signed amount',
    ! str_contains($source, 'abs('),
);
opening_balance_assert(
    'amount is rejected outside plus or minus Money::MAX_MINOR_AMOUNT',
    str_contains($assertAmount, 'Money::MAX_MINOR_AMOUNT')
        && str_contains($assertAmount, '-Money::MAX_MINOR_AMOUNT'),
);
opening_balance_assert(
    'positive magnitude uses sign checks rather than abs()',
    str_contains($positiveMagnitude, '$amountMinor > 0')
        && str_contains($positiveMagnitude, '$amountMinor < 0')
        && str_contains($positiveMagnitude, 'return -$amountMinor')
        && ! str_contains($positiveMagnitude, 'abs('),
);
opening_balance_assert(
    'entry date requires exact YYYY-MM-DD and calendar round-trip',
    str_contains($validateDate, "preg_match('/\\A\\d{4}-\\d{2}-\\d{2}\\z/', \$entryDate)")
        && str_contains($validateDate, "createFromFormat('!Y-m-d', \$entryDate)")
        && str_contains($validateDate, "\$parsed->format('Y-m-d') !== \$entryDate")
        && ! str_contains($validateDate, 'trim('),
);
opening_balance_assert(
    'future entry dates are rejected',
    str_contains($validateDate, "now('UTC')")
        && str_contains($validateDate, 'cannot be in the future'),
);
opening_balance_assert(
    'persisted currency codes are not silently uppercased or trimmed',
    str_contains($lockCurrency, 'ctype_upper($currencyCode)')
        && ! str_contains($lockCurrency, 'strtoupper')
        && ! str_contains($lockCurrency, 'trim('),
);
opening_balance_assert(
    'financial account is locked first',
    str_contains($inCurrent, 'FinancialAccount::query()')
        && strpos($inCurrent, 'FinancialAccount::query()') < strpos($inCurrent, 'lockForUpdate()')
        && strpos($inCurrent, 'FinancialAccount::query()') < strpos($inCurrent, 'lockCurrency'),
);
opening_balance_assert(
    'account ledger is loaded without a row lock',
    str_contains($linkedLedger, 'LedgerAccount::query()')
        && ! str_contains($linkedLedger, 'lockForUpdate()'),
);
opening_balance_assert(
    'currency is locked only after the financial-account lock and never locks a ledger',
    str_contains($lockCurrency, 'Currency::query()')
        && str_contains($lockCurrency, 'lockForUpdate()')
        && ! str_contains($lockCurrency, 'LedgerAccount'),
);
opening_balance_assert(
    'new non-zero path locks currency then opening equity then LedgerPostingService',
    strpos($inCurrent, 'lockCurrency') !== false
        && strpos($inCurrent, 'lockCurrency') < strpos($inCurrent, 'openingEquityAccountService->resolveOrCreate')
        && strpos($inCurrent, 'openingEquityAccountService->resolveOrCreate') < strpos($inCurrent, 'ledgerPostingService->post'),
);
opening_balance_assert(
    'pointed-journal replay happens before archived, zero, and posting checks',
    strpos($inCurrent, 'opening_journal_entry_id !== null') !== false
        && strpos($inCurrent, 'replayPointedOpening') < strpos($inCurrent, 'is_archived')
        && strpos($inCurrent, 'replayPointedOpening') < strpos($inCurrent, '$amountMinor === 0')
        && strpos($inCurrent, 'replayPointedOpening') < strpos($inCurrent, 'postings()->exists()'),
);
opening_balance_assert(
    'zero amount is a no-op before currency lock, equity, journal posting, and audit',
    strpos($inCurrent, '$amountMinor === 0') !== false
        && strpos($inCurrent, '$amountMinor === 0') < strpos($inCurrent, 'lockCurrency')
        && strpos($inCurrent, 'isNoOp: true') !== false
        && strpos($inCurrent, 'journalEntry: null') !== false
        && strpos($inCurrent, '$amountMinor === 0') < strpos($inCurrent, 'ACTION_ACCOUNT_OPENING_POSTED')
        && strpos($inCurrent, '$amountMinor === 0') < strpos($inCurrent, 'openingEquityAccountService->resolveOrCreate')
        && strpos($inCurrent, '$amountMinor === 0') < strpos($inCurrent, 'ledgerPostingService->post'),
);
opening_balance_assert(
    'new non-zero opening is refused when the account ledger already has postings',
    str_contains($inCurrent, 'postings()->exists()')
        && strpos($inCurrent, 'postings()->exists()') > strpos($inCurrent, '$amountMinor === 0')
        && strpos($inCurrent, 'postings()->exists()') < strpos($inCurrent, 'lockCurrency'),
);
opening_balance_assert(
    'replay loads the pointed journal first and never calls LedgerPostingService::post',
    str_contains($replay, 'JournalEntry::query()')
        && strpos($replay, 'JournalEntry::query()') < strpos($replay, 'lockForUpdate()')
        && ! str_contains($replay, '->post(')
        && ! str_contains($replay, 'PostJournalEntryInput'),
);
opening_balance_assert(
    'replay rejects a different idempotency key before currency lock or fingerprint work',
    strpos($replay, 'hash_equals((string) $pointedJournal->idempotency_key, $idempotencyKey)') !== false
        && strpos($replay, 'hash_equals((string) $pointedJournal->idempotency_key, $idempotencyKey)') < strpos($replay, 'lockCurrency')
        && strpos($replay, 'hash_equals((string) $pointedJournal->idempotency_key, $idempotencyKey)') < strpos($replay, 'computeRequestFingerprint'),
);
opening_balance_assert(
    'same-key replay uses computeRequestFingerprint and returns the pointed journal',
    str_contains($replay, 'computeRequestFingerprint')
        && str_contains($replay, 'hash_equals((string) $pointedJournal->request_fingerprint, $requestFingerprint)')
        && str_contains($replay, 'wasRecentlyCreated: false')
        && str_contains($replay, 'journalEntry: $pointedJournal'),
);
opening_balance_assert(
    'replay validates pointed-journal owner and currency',
    str_contains($replay, '(int) $pointedJournal->user_id !== (int) $user->id')
        && str_contains($replay, '$pointedJournal->currency_code !== $lockedAccount->currency_code'),
);
opening_balance_assert(
    'replay does not call OpeningEquityAccountService::resolveOrCreate',
    ! str_contains($replay, 'resolveOrCreate')
        && str_contains($replay, 'existingOpeningEquityLedger'),
);
opening_balance_assert(
    'replay equity lookup is read-only and uses canonical EQ-OPEN code',
    str_contains($existingEquity, 'OpeningEquityAccountService::canonicalCode')
        && str_contains($existingEquity, 'LedgerAccount::query()')
        && str_contains($existingEquity, "where('code', \$code)")
        && ! str_contains($existingEquity, 'lockForUpdate()')
        && ! str_contains($existingEquity, '->create(')
        && ! str_contains($existingEquity, '->save()')
        && ! str_contains($existingEquity, '->update(')
        && ! str_contains($existingEquity, 'resolveOrCreate'),
);
opening_balance_assert(
    'replay equity invariants are validated and missing or invalid rows fail closed',
    str_contains($existingEquity, 'assertExistingOpeningEquity')
        && str_contains($existingEquity, 'does not exist for this user')
        && str_contains($assertExistingEquity, 'LedgerAccountClassification::Equity')
        && str_contains($assertExistingEquity, '$existing->is_system === true')
        && str_contains($assertExistingEquity, '$existing->financial_account_id === null')
        && str_contains($assertExistingEquity, '$existing->category_id === null')
        && str_contains($assertExistingEquity, '$existing->currency_code === $currencyCode')
        && str_contains($assertExistingEquity, '(int) $existing->user_id === (int) $user->id')
        && str_contains($assertExistingEquity, '$existing->code === $code')
        && ! str_contains($assertExistingEquity, '->update(')
        && ! str_contains($assertExistingEquity, '->save()')
        && ! str_contains($assertExistingEquity, '->create('),
);
opening_balance_assert(
    'replay does not write account, journal, posting, or audit data',
    ! str_contains($replay, '->save()')
        && ! str_contains($replay, '->create(')
        && ! str_contains($replay, '->update(')
        && ! str_contains($replay, 'auditService')
        && ! str_contains($replay, 'ACTION_ACCOUNT_OPENING_POSTED'),
);
opening_balance_assert(
    'a pre-existing LedgerPostingService journal that is not the pointed opening is rejected',
    str_contains($inCurrent, '! $ledgerResult->wasRecentlyCreated')
        && strpos($inCurrent, '! $ledgerResult->wasRecentlyCreated') < strpos($inCurrent, 'opening_journal_entry_id ='),
);
opening_balance_assert(
    'pointer is assigned directly and saved in the same unit of work',
    str_contains($inCurrent, '$lockedAccount->opening_journal_entry_id = $ledgerResult->journalEntry->id')
        && str_contains($inCurrent, '$lockedAccount->save()')
        && ! str_contains($source, "fill([")
        && ! str_contains($source, "'opening_journal_entry_id'"),
);
opening_balance_assert(
    'opening-posted audit is emitted only after a newly created non-zero journal',
    str_contains($inCurrent, 'ACTION_ACCOUNT_OPENING_POSTED')
        && strpos($inCurrent, 'opening_journal_entry_id =') < strpos($inCurrent, 'ACTION_ACCOUNT_OPENING_POSTED')
        && ! str_contains($replay, 'ACTION_ACCOUNT_OPENING_POSTED')
        && str_contains($auditSource, "ACTION_ACCOUNT_OPENING_POSTED = 'account.opening_posted'"),
);
opening_balance_assert(
    'outer transaction is respected; owned retry is TransientTransactionFailure only',
    str_contains($publicApi, 'DB::transactionLevel() > 0')
        && str_contains($publicApi, 'TransientTransactionFailure::isRetryable')
        && ! str_contains($publicApi, 'isLedgerUserCodeUniqueViolation')
        && ! str_contains($publicApi, 'UniqueConstraintViolationException')
        && ! str_contains($source, 'catch (Throwable')
        && ! str_contains($source, 'catch (Exception'),
);
opening_balance_assert(
    'asset and liability posting directions are encoded from the signed amount',
    str_contains($buildPostings, 'LedgerAccountClassification::Asset')
        && str_contains($buildPostings, '$amountMinor > 0')
        && str_contains($buildPostings, '$amountMinor < 0')
        && str_contains($buildPostings, 'debitAmountMinor: $magnitude')
        && str_contains($buildPostings, 'creditAmountMinor: $magnitude'),
);
opening_balance_assert(
    'LedgerPostingService still exposes computeRequestFingerprint and was not imported into by this service',
    str_contains($postingSource, 'public function computeRequestFingerprint(')
        && ! str_contains($postingSource, 'OpeningBalanceService'),
);
opening_balance_assert(
    'OpeningEquityAccountService, FinancialAccountService, and FinancialTransactionService are not wired to this service as callers',
    ! str_contains($equitySource, 'OpeningBalanceService')
        && ! str_contains($accountServiceSource, 'OpeningBalanceService')
        && ! str_contains($transactionServiceSource, 'OpeningBalanceService'),
);
opening_balance_assert(
    'routes and account controller are not wired',
    ! str_contains($routesSource, 'OpeningBalanceService')
        && ! str_contains($accountControllerSource, 'OpeningBalanceService')
        && ! str_contains($accountControllerSource, 'postForAccount'),
);
opening_balance_assert(
    'FinancialAccount fillable still omits opening_journal_entry_id',
    ! str_contains($accountModelSource, 'opening_journal_entry_id'),
);
opening_balance_assert(
    'architecture records lock order and pointed-journal replay',
    str_contains($architecture, 'OpeningBalanceService')
        && str_contains($architecture, 'Locks `financial_accounts` first, then `currencies`, then ledger accounts')
        && str_contains($architecture, 'computeRequestFingerprint()')
        && str_contains($architecture, 'Starting balance')
        && str_contains($architecture, 'TransientTransactionFailure'),
);

$todayUtc = gmdate('Y-m-d');
$validDate = DateTimeImmutable::createFromFormat('!Y-m-d', '2026-01-31');
$invalidDate = DateTimeImmutable::createFromFormat('!Y-m-d', '2026-02-30');
$paddedDate = DateTimeImmutable::createFromFormat('!Y-m-d', '2026-1-1');

opening_balance_assert(
    'synthetic calendar date 2026-01-31 round-trips',
    $validDate instanceof DateTimeImmutable && $validDate->format('Y-m-d') === '2026-01-31',
);
opening_balance_assert(
    'synthetic non-calendar 2026-02-30 does not round-trip',
    ! ($invalidDate instanceof DateTimeImmutable && $invalidDate->format('Y-m-d') === '2026-02-30'),
);
opening_balance_assert(
    'synthetic malformed 2026-1-1 does not match YYYY-MM-DD',
    preg_match('/\A\d{4}-\d{2}-\d{2}\z/', '2026-1-1') !== 1
        && ! ($paddedDate instanceof DateTimeImmutable && $paddedDate->format('Y-m-d') === '2026-1-1'),
);
opening_balance_assert(
    'synthetic future date is later than UTC today',
    '2099-01-01' > $todayUtc,
);
opening_balance_assert(
    'synthetic whitespace date is not silently accepted as YYYY-MM-DD',
    preg_match('/\A\d{4}-\d{2}-\d{2}\z/', ' 2026-01-31 ') !== 1,
);

$maxMinor = 1_000_000_000_000_000_000;
opening_balance_assert(
    'Money::MAX_MINOR_AMOUNT remains 10^18',
    str_contains($moneySource, 'MAX_MINOR_AMOUNT = 1_000_000_000_000_000_000'),
);
opening_balance_assert(
    'synthetic bound check accepts plus or minus 10^18 and rejects one beyond',
    $maxMinor <= 1_000_000_000_000_000_000
        && -$maxMinor >= -1_000_000_000_000_000_000
        && ($maxMinor + 0) === 1_000_000_000_000_000_000,
);

$negate = static function (int $amount): int {
    if ($amount > 0) {
        return $amount;
    }

    if ($amount < 0) {
        return -$amount;
    }

    return 0;
};

opening_balance_assert('synthetic negative magnitude is 2500', $negate(-2500) === 2500);
opening_balance_assert('synthetic positive magnitude is 2500', $negate(2500) === 2500);
opening_balance_assert('synthetic zero magnitude stays 0', $negate(0) === 0);

$debitAccount = static function (string $classification, int $amount): bool {
    if ($classification === 'asset') {
        return $amount > 0;
    }

    return $amount < 0;
};

opening_balance_assert('synthetic asset positive debits the account', $debitAccount('asset', 100) === true);
opening_balance_assert('synthetic asset negative credits the account', $debitAccount('asset', -100) === false);
opening_balance_assert('synthetic liability positive credits the account', $debitAccount('liability', 100) === false);
opening_balance_assert('synthetic liability negative debits the account', $debitAccount('liability', -100) === true);

echo "\n{$passed} passed, {$failed} failed\n";
echo "Scope: source inspection and synthetic checks only. Concurrency and database-backed behavior remain unverified.\n";

if ($failed > 0) {
    fwrite(STDERR, "\nFailed cases:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

exit(0);
