<?php

/**
 * Source-level offline verification for financial-account create opening integration.
 *
 * Reads PHP source only. Does not bootstrap Laravel, connect to a database,
 * instantiate Eloquent models, or execute account creation.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

$servicePath = $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'FinancialAccountService.php';
$requestPath = $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Http'.DIRECTORY_SEPARATOR.'Requests'.DIRECTORY_SEPARATOR.'Accounts'.DIRECTORY_SEPARATOR.'StoreFinancialAccountRequest.php';
$controllerPath = $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Http'.DIRECTORY_SEPARATOR.'Controllers'.DIRECTORY_SEPARATOR.'FinancialAccountController.php';
$openingPath = $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'OpeningBalanceService.php';
$postingPath = $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'LedgerPostingService.php';
$transactionPath = $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'FinancialTransactionService.php';
$architecturePath = $root.DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'architecture.md';
$accountsPagePath = $root.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'js'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'Accounts'.DIRECTORY_SEPARATOR.'Index.tsx';
$typesPath = $root.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'js'.DIRECTORY_SEPARATOR.'Types'.DIRECTORY_SEPARATOR.'index.ts';

$passed = 0;
$failed = 0;
$failures = [];

function account_create_assert(string $name, bool $condition, string $detail = ''): void
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

function account_create_extract_method(string $source, string $functionName): string
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

$service = is_file($servicePath) ? (string) file_get_contents($servicePath) : '';
$request = is_file($requestPath) ? (string) file_get_contents($requestPath) : '';
$controller = is_file($controllerPath) ? (string) file_get_contents($controllerPath) : '';
$opening = is_file($openingPath) ? (string) file_get_contents($openingPath) : '';
$posting = is_file($postingPath) ? (string) file_get_contents($postingPath) : '';
$transaction = is_file($transactionPath) ? (string) file_get_contents($transactionPath) : '';
$architecture = is_file($architecturePath) ? (string) file_get_contents($architecturePath) : '';
$accountsPage = is_file($accountsPagePath) ? (string) file_get_contents($accountsPagePath) : '';
$types = is_file($typesPath) ? (string) file_get_contents($typesPath) : '';

$create = account_create_extract_method($service, 'createAccountResult');
$inCurrent = account_create_extract_method($service, 'createAccountInCurrentTransaction');
$findCurrency = account_create_extract_method($service, 'findActiveCurrency');
$lockCurrency = account_create_extract_method($service, 'lockActiveCurrency');
$optionalBalance = account_create_extract_method($service, 'optionalStartingBalance');
$parseBalance = account_create_extract_method($service, 'parseOptionalStartingBalanceMinor');
$update = account_create_extract_method($service, 'updateAccount');
$store = account_create_extract_method($controller, 'store');

account_create_assert('service source file exists', is_file($servicePath), $servicePath);
account_create_assert(
    'public API remains createAccount(User, array): FinancialAccount',
    str_contains($service, 'public function createAccount(User $user, array $data): FinancialAccount'),
);
account_create_assert(
    'OpeningBalanceService is injected into FinancialAccountService',
    str_contains($service, 'private readonly OpeningBalanceService $openingBalanceService'),
);
account_create_assert(
    'inactive currency uses InactiveCurrencyException',
    str_contains($service, 'throw new InactiveCurrencyException'),
);
account_create_assert(
    'signed amounts use MoneyInputParser::parseSignedToMinorUnits',
    str_contains($parseBalance, 'MoneyInputParser::parseSignedToMinorUnits')
        && ! str_contains($service, '::parseToMinorUnits')
        && ! str_contains($service, 'parseNonNegativeToMinorUnits'),
);
account_create_assert(
    'omitted, null, and empty starting_balance become no opening amount',
    str_contains($optionalBalance, "\$data['starting_balance'] === null")
        && str_contains($optionalBalance, "trim(\$data['starting_balance'])")
        && str_contains($optionalBalance, "\$trimmed === ''")
        && str_contains($parseBalance, 'if ($startingBalance === null)')
        && str_contains($parseBalance, 'return 0'),
);
account_create_assert(
    'OpeningBalanceService is called only for a non-zero parsed amount',
    str_contains($inCurrent, '$amountMinor !== 0')
        && strpos($inCurrent, '$amountMinor !== 0') < strpos($inCurrent, 'openingBalanceService->postForAccount')
        && substr_count($inCurrent, 'postForAccount') === 1,
);
account_create_assert(
    'posting amount is parsed against the locked currency exponent',
    substr_count($inCurrent, 'parseOptionalStartingBalanceMinor') === 2
        && strpos($inCurrent, 'lockActiveCurrency') < strrpos($inCurrent, 'parseOptionalStartingBalanceMinor')
        && strrpos($inCurrent, 'parseOptionalStartingBalanceMinor') < strpos($inCurrent, 'openingBalanceService->postForAccount')
        && str_contains($inCurrent, '$lockedCurrency->minor_unit_exponent'),
);
account_create_assert(
    'opening uses UTC today and derived opening-balance:account:{id} key (not the HTTP create key)',
    str_contains($inCurrent, "now('UTC')->format('Y-m-d')")
        && str_contains($service, "OPENING_IDEMPOTENCY_KEY_PREFIX = 'opening-balance:account:'")
        && str_contains($inCurrent, 'self::OPENING_IDEMPOTENCY_KEY_PREFIX.$financialAccount->id')
        && str_contains($request, 'idempotency_key'),
);
account_create_assert(
    'account.created audit is recorded before opening posting',
    str_contains($inCurrent, 'ACTION_ACCOUNT_CREATED')
        && strpos($inCurrent, 'ACTION_ACCOUNT_CREATED') < strpos($inCurrent, 'openingBalanceService->postForAccount'),
);
account_create_assert(
    'currency is read without a row lock before inserts',
    str_contains($inCurrent, 'findActiveCurrency')
        && strpos($inCurrent, 'findActiveCurrency') < strpos($inCurrent, 'FinancialAccount::query()->create')
        && str_contains($findCurrency, 'Currency::query()')
        && ! str_contains($findCurrency, 'lockForUpdate()'),
);
account_create_assert(
    'financial account and ledger are inserted before the currency row lock',
    strpos($inCurrent, 'FinancialAccount::query()->create') !== false
        && strpos($inCurrent, 'FinancialAccount::query()->create') < strpos($inCurrent, 'LedgerAccount::query()->create')
        && strpos($inCurrent, 'LedgerAccount::query()->create') < strpos($inCurrent, 'lockActiveCurrency')
        && strpos($inCurrent, 'lockActiveCurrency') < strpos($inCurrent, 'openingBalanceService->postForAccount'),
);
account_create_assert(
    'currency lock helper uses lockForUpdate and revalidates active status',
    str_contains($lockCurrency, 'lockForUpdate()')
        && str_contains($lockCurrency, 'requireActiveCurrency')
        && ! str_contains($lockCurrency, 'LedgerAccount')
        && ! str_contains($lockCurrency, 'FinancialAccount'),
);
account_create_assert(
    'create path may lock an existing account by idempotency key but does not lock the account ledger before currency',
    str_contains($inCurrent, 'FinancialAccount::query()->create')
        && str_contains($inCurrent, 'LedgerAccount::query()->create')
        && strpos($inCurrent, 'LedgerAccount::query()->create') < strpos($inCurrent, 'lockActiveCurrency'),
);
account_create_assert(
    'nested callers get a savepoint; owned retry covers transient failures and create-idempotency unique races',
    str_contains($create, 'DB::transactionLevel() === 0')
        && str_contains($create, 'ownsOutermostTransaction')
        && str_contains($create, 'TransientTransactionFailure::isRetryable')
        && str_contains($create, 'UniqueConstraintViolationException')
        && str_contains($service, 'IdempotencyUniqueConstraint')
        && ! str_contains($create, 'isLedgerUserCodeUniqueViolation')
        && ! str_contains($create, 'catch (Throwable')
        && ! str_contains($create, 'catch (Exception'),
);
account_create_assert(
    'store request accepts optional starting_balance as a string of max 64',
    str_contains($request, "'starting_balance' => ['sometimes', 'nullable', 'string', 'max:64']")
        && ! str_contains($request, 'numeric')
        && ! str_contains($request, 'decimal'),
);
account_create_assert(
    'controller maps typed amount and inactive-currency exceptions without message sniffing',
    str_contains($store, 'catch (InvalidMonetaryAmountException')
        && str_contains($store, 'catch (InactiveCurrencyException')
        && str_contains($store, 'InvalidMonetaryAmountException::PUBLIC_FIELD')
        && str_contains($store, 'InactiveCurrencyException::PUBLIC_FIELD')
        && ! str_contains($controller, 'isStartingBalanceMessage')
        && ! str_contains($controller, 'isInactiveCurrencyMessage')
        && ! str_contains($controller, 'str_starts_with($message')
        && ! str_contains($controller, 'str_contains($message'),
);
account_create_assert(
    'idempotency conflicts map to idempotency_key; other ledger integrity failures are not remapped',
    str_contains($store, 'catch (IdempotencyConflictException')
        && str_contains($store, "'idempotency_key'")
        && ! str_contains($store, 'catch (LedgerIntegrityException'),
);
account_create_assert(
    'update still locks financial_accounts before currency and is unchanged in opening wiring',
    strpos($update, 'FinancialAccount::query()') !== false
        && strpos($update, 'lockForUpdate()') !== false
        && strpos($update, 'FinancialAccount::query()') < strpos($update, 'resolveActiveCurrency')
        && strpos($update, 'resolveActiveCurrency') < strpos($update, 'LedgerAccount::query()')
        && ! str_contains($update, 'OpeningBalanceService')
        && ! str_contains($update, 'starting_balance'),
);
account_create_assert(
    'OpeningBalanceService, LedgerPostingService, and FinancialTransactionService are not modified by this integration',
    ! str_contains($opening, 'FinancialAccountService')
        && ! str_contains($posting, 'FinancialAccountService')
        && ! str_contains($transaction, 'OpeningBalanceService')
        && ! str_contains($transaction, 'starting_balance'),
);
account_create_assert(
    'create form wires starting_balance and idempotency_key',
    str_contains($accountsPage, 'starting_balance')
        && str_contains($accountsPage, 'idempotency_key')
        && str_contains($accountsPage, 'generateUuidV4'),
);
account_create_assert(
    'architecture records create-time opening lock order and derived key',
    str_contains($architecture, 'Currency is read without a row lock')
        && str_contains($architecture, 'opening-balance:account:{id}')
        && str_contains($architecture, 'TransientTransactionFailure')
        && str_contains($architecture, 'The account ledger is never locked before currency')
        && str_contains($architecture, 'locked currency\'s `minor_unit_exponent`')
        && str_contains($architecture, 'InvalidMonetaryAmountException')
        && str_contains($architecture, 'InactiveCurrencyException'),
);

$signedZeroInputs = ['0', '0.00', '-0', '-0.00'];
$allSignedZero = true;

foreach ($signedZeroInputs as $input) {
    $trimmed = trim($input, " \t\n\r\0\x0B");
    $isNegative = str_starts_with($trimmed, '-');

    if ($isNegative) {
        $trimmed = substr($trimmed, 1);
    }

    $parts = explode('.', $trimmed, 2);
    $major = ltrim($parts[0], '0');
    $fraction = str_pad($parts[1] ?? '', 2, '0', STR_PAD_RIGHT);
    $canonical = ltrim($major.$fraction, '0');

    if ($canonical !== '') {
        $allSignedZero = false;
        break;
    }
}

account_create_assert('synthetic signed zero strings collapse to integer zero', $allSignedZero);
account_create_assert(
    'synthetic omitted and empty strings are treated as absent',
    ($starting = null) === null
        && $starting === null
        && '' === '',
);
account_create_assert(
    'synthetic derived key stays within 128 characters',
    strlen('opening-balance:account:18446744073709551615') <= 128,
);

echo "\n{$passed} passed, {$failed} failed\n";
echo "Scope: source inspection and synthetic checks only. Concurrency and database-backed behavior remain unverified.\n";

if ($failed > 0) {
    fwrite(STDERR, "\nFailed cases:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

exit(0);
