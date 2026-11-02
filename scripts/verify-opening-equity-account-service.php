<?php

/**
 * Source-level offline verification for OpeningEquityAccountService.
 *
 * Reads PHP source only. Does not bootstrap Laravel, connect to a database,
 * instantiate Eloquent models, or execute resolve-or-create.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$servicePath = $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'OpeningEquityAccountService.php';
$accountServicePath = $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'FinancialAccountService.php';
$postingServicePath = $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'LedgerPostingService.php';
$migrationPath = $root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR.'2026_10_27_000003_create_ledger_accounts_table.php';

$passed = 0;
$failed = 0;
$failures = [];

function equity_open_assert(string $name, bool $condition, string $detail = ''): void
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

equity_open_assert('service source file exists', is_file($servicePath), $servicePath);

$source = is_file($servicePath) ? (string) file_get_contents($servicePath) : '';
$accountSource = is_file($accountServicePath) ? (string) file_get_contents($accountServicePath) : '';
$postingSource = is_file($postingServicePath) ? (string) file_get_contents($postingServicePath) : '';
$migrationSource = is_file($migrationPath) ? (string) file_get_contents($migrationPath) : '';

$inCurrentStart = strpos($source, 'private function resolveOrCreateInCurrentTransaction');
$inCurrentSource = $inCurrentStart === false ? '' : substr($source, $inCurrentStart);

equity_open_assert(
    'class OpeningEquityAccountService is defined',
    str_contains($source, 'class OpeningEquityAccountService'),
);
equity_open_assert(
    'canonical code prefix is EQ-OPEN-',
    str_contains($source, "CODE_PREFIX = 'EQ-OPEN-'"),
);
equity_open_assert(
    'canonical name is Opening Balances Equity',
    str_contains($source, "CANONICAL_NAME = 'Opening Balances Equity'"),
);
equity_open_assert(
    'canonicalCode builds EQ-OPEN-{CCY} from a strict canonical code',
    str_contains($source, 'function canonicalCode')
        && str_contains($source, 'self::CODE_PREFIX.self::assertCanonicalCurrencyCode'),
);
equity_open_assert(
    'public resolveOrCreate(User, Currency) exists',
    str_contains($source, 'public function resolveOrCreate(User $user, Currency $currency): LedgerAccount'),
);
equity_open_assert(
    'equity classification is used on create',
    str_contains($source, 'LedgerAccountClassification::Equity'),
);
equity_open_assert(
    'create sets is_system true',
    str_contains($source, "'is_system' => true"),
);
equity_open_assert(
    'create sets financial_account_id null',
    str_contains($source, "'financial_account_id' => null"),
);
equity_open_assert(
    'create sets category_id null',
    str_contains($source, "'category_id' => null"),
);
equity_open_assert(
    'reuse validates ownership, currency, classification, system flag, and parent links',
    str_contains($source, 'assertReusableOpeningEquity')
        && str_contains($source, '$existing->is_system === true')
        && str_contains($source, '$existing->financial_account_id === null')
        && str_contains($source, '$existing->category_id === null')
        && str_contains($source, '$classification === LedgerAccountClassification::Equity')
        && str_contains($source, '$existing->currency_code === $currencyCode')
        && str_contains($source, '(int) $existing->user_id === (int) $user->id'),
);
equity_open_assert(
    'invariant mismatch fails closed without repair',
    str_contains($source, 'already exists for this user but is not a valid Opening Balances Equity system account.')
        && ! str_contains($source, '->update(')
        && ! str_contains($source, '->fill(')
        && ! str_contains($source, '->save()'),
);
equity_open_assert(
    'currency row is locked before ledger lookup and create',
    str_contains($inCurrentSource, 'Currency::query()')
        && strpos($inCurrentSource, 'lockForUpdate()') < strpos($inCurrentSource, 'LedgerAccount::query()')
        && strpos($inCurrentSource, 'LedgerAccount::query()') < strpos($inCurrentSource, 'LedgerAccount::query()->create'),
);
equity_open_assert(
    'lockForUpdate is used on currency then existing ledger row',
    substr_count($source, 'lockForUpdate()') === 2,
);
equity_open_assert(
    'caller lock protocol is documented',
    str_contains($source, 'same default database connection')
        && str_contains($source, 'currencies first, then')
        && str_contains($source, 'ledger_accounts'),
);
equity_open_assert(
    'duplicate-key INSERT is not recovered in-place',
    ! str_contains($source, 'lockExistingOrFail')
        && ! str_contains($inCurrentSource, 'catch (QueryException')
        && str_contains($source, 'UNIQUE (user_id, code) remains the final safeguard'),
);
equity_open_assert(
    'owned-transaction retries unique (user_id, code) or TransientTransactionFailure after rollback',
    str_contains($source, 'isOwnedTransactionRetryable')
        && str_contains($source, 'TransientTransactionFailure::isRetryable')
        && str_contains($source, 'isLedgerUserCodeUniqueViolation')
        && str_contains($source, 'ledger_accounts_user_id_code_unique'),
);
equity_open_assert(
    'outer transaction receives duplicate-key exceptions instead of in-place recovery',
    str_contains($source, 'DB::transactionLevel() > 0')
        && str_contains($source, 'When an outer transaction already exists, the exception is propagated'),
);
equity_open_assert(
    'unique classification does not treat SQLSTATE 23000 as sufficient',
    str_contains($source, 'IdempotencyUniqueConstraint::isDuplicateKeyViolation')
        && ! str_contains($source, "sqlState === '23000'"),
);
equity_open_assert(
    'unrelated QueryException is rethrown from the owned-transaction loop',
    str_contains($source, 'throw $exception;'),
);
equity_open_assert(
    'does not catch Throwable or generic Exception',
    ! str_contains($source, 'catch (Throwable')
        && ! str_contains($source, 'catch (Exception')
        && ! str_contains($source, 'catch (\\Throwable')
        && ! str_contains($source, 'catch (\\Exception'),
);
equity_open_assert(
    'does not post journals or change financial accounts',
    ! str_contains($source, 'LedgerPostingService')
        && ! str_contains($source, 'JournalEntry')
        && ! str_contains($source, 'JournalPosting')
        && ! str_contains($source, 'FinancialAccount::')
        && ! str_contains($source, 'FinancialTransaction'),
);
equity_open_assert(
    'inactive currency blocks create but existing rows are still reusable',
    str_contains($source, '! $lockedCurrency->is_active')
        && str_contains($source, 'if ($existing !== null)'),
);
equity_open_assert(
    'persisted currency codes are not silently uppercased or trimmed',
    str_contains($source, 'assertCanonicalCurrencyCode')
        && ! str_contains($source, 'strtoupper')
        && ! str_contains($source, 'trim(')
        && str_contains($source, 'ctype_upper($currencyCode)')
        && str_contains($source, '$lockedCurrency->code !== $currencyCode'),
);

equity_open_assert(
    'existing schema unique(user_id, code) is present',
    str_contains($migrationSource, "unique(['user_id', 'code'])"),
);
equity_open_assert(
    'existing schema supports nullable financial_account_id and is_system',
    str_contains($migrationSource, "boolean('is_system')->default(false)")
        && str_contains($migrationSource, "unsignedBigInteger('financial_account_id')->nullable()"),
);
equity_open_assert(
    'FinancialAccountService does not yet call opening equity provisioning',
    ! str_contains($accountSource, 'OpeningEquityAccountService'),
);
equity_open_assert(
    'LedgerPostingService is unchanged by this service import',
    ! str_contains($postingSource, 'OpeningEquityAccountService'),
);

$syntheticCode = 'EQ-OPEN-USD';
$expectedFromPrefix = 'EQ-OPEN-'.'USD';
equity_open_assert(
    'synthetic USD code matches EQ-OPEN-USD',
    $syntheticCode === $expectedFromPrefix && $syntheticCode === 'EQ-OPEN-USD',
);
equity_open_assert(
    'synthetic PHP code matches EQ-OPEN-PHP',
    'EQ-OPEN-'.'PHP' === 'EQ-OPEN-PHP',
);
equity_open_assert(
    'canonical name length fits ledger_accounts.name (120)',
    strlen('Opening Balances Equity') <= 120,
);
equity_open_assert(
    'canonical code length fits ledger_accounts.code (64)',
    strlen('EQ-OPEN-USD') <= 64 && strlen('EQ-OPEN-JPY') <= 64,
);
equity_open_assert(
    'lowercase or padded codes are not canonical',
    preg_match('/\A[A-Z]{3}\z/', 'usd') !== 1
        && preg_match('/\A[A-Z]{3}\z/', 'USD ') !== 1
        && preg_match('/\A[A-Z]{3}\z/', 'US') !== 1
        && preg_match('/\A[A-Z]{3}\z/', 'USD') === 1,
);

echo "\n{$passed} passed, {$failed} failed\n";
echo "Scope: source inspection and synthetic strings only. Concurrent insert behavior was not executed.\n";

if ($failed > 0) {
    fwrite(STDERR, "\nFailed cases:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

exit(0);
