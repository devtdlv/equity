<?php

/**
 * Source-level offline verification for the opening journal reference migration.
 *
 * Reads PHP and Markdown source only. Does not bootstrap Laravel, connect to a
 * database, or run migrations.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$migrationPath = $root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR.'2026_11_02_000015_add_opening_journal_entry_to_financial_accounts_table.php';
$parentMigrationPath = $root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR.'2026_10_27_000009_create_financial_transactions_table.php';
$accountModelPath = $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Models'.DIRECTORY_SEPARATOR.'FinancialAccount.php';
$architecturePath = $root.DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'architecture.md';

$passed = 0;
$failed = 0;
$failures = [];

function equity_open_ref_assert(string $name, bool $condition, string $detail = ''): void
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

$migration = is_file($migrationPath) ? (string) file_get_contents($migrationPath) : '';
$parent = is_file($parentMigrationPath) ? (string) file_get_contents($parentMigrationPath) : '';
$model = is_file($accountModelPath) ? (string) file_get_contents($accountModelPath) : '';
$architecture = is_file($architecturePath) ? (string) file_get_contents($architecturePath) : '';

equity_open_ref_assert('migration file exists', is_file($migrationPath), $migrationPath);

equity_open_ref_assert(
    'nullable unsignedBigInteger opening_journal_entry_id is added',
    str_contains($migration, "unsignedBigInteger('opening_journal_entry_id')->nullable()"),
);
equity_open_ref_assert(
    'UNIQUE(opening_journal_entry_id) is named',
    str_contains($migration, "unique('opening_journal_entry_id', self::UNIQUE_INDEX)")
        && str_contains($migration, "UNIQUE_INDEX = 'financial_accounts_opening_journal_entry_id_unique'"),
);
equity_open_ref_assert(
    'composite FK matches journal_entries(id, user_id, currency_code)',
    str_contains($migration, "['opening_journal_entry_id', 'user_id', 'currency_code']")
        && str_contains($migration, "references(['id', 'user_id', 'currency_code'])")
        && str_contains($migration, "on('journal_entries')")
        && str_contains($migration, 'restrictOnDelete()'),
);
equity_open_ref_assert(
    'no redundant single-column FK on opening_journal_entry_id',
    ! str_contains($migration, "foreign('opening_journal_entry_id'")
        && ! str_contains($migration, 'constrained('),
);
equity_open_ref_assert(
    'parent unique (id, user_id, currency_code) exists in 000009',
    str_contains($parent, "['id', 'user_id', 'currency_code']")
        && str_contains($parent, 'journal_entries_id_user_id_currency_code_unique'),
);
equity_open_ref_assert(
    'migration does not insert journals or backfill pointers',
    ! str_contains($migration, 'JournalEntry')
        && ! str_contains($migration, 'DB::table')
        && ! str_contains($migration, '->update(')
        && ! str_contains($migration, '->insert('),
);
equity_open_ref_assert(
    'rollback drops composite FK, unique index, then column',
    str_contains($migration, 'function down')
        && str_contains($migration, 'dropForeign(self::COMPOSITE_FOREIGN)')
        && str_contains($migration, 'dropUnique(self::UNIQUE_INDEX)')
        && str_contains($migration, "dropColumn('opening_journal_entry_id')"),
);
equity_open_ref_assert(
    'rollback drops the composite FK by explicit constraint name',
    str_contains($migration, 'dropForeign(self::COMPOSITE_FOREIGN)')
        && ! str_contains($migration, "dropForeign(['opening_journal_entry_id', 'user_id', 'currency_code'])"),
);
equity_open_ref_assert(
    'supported drivers are sqlite, mariadb, mysql',
    str_contains($migration, "['sqlite', 'mariadb', 'mysql']"),
);
equity_open_ref_assert(
    'SQLite rebuild drops and recreates the financial_transactions insert trigger',
    str_contains($migration, "\$driver === 'sqlite'")
        && str_contains($migration, 'dropFinancialTransactionInsertTrigger')
        && str_contains($migration, 'createSqliteFinancialTransactionInsertTrigger')
        && str_contains($migration, 'DROP TRIGGER IF EXISTS trg_financial_transactions_validate_insert')
        && str_contains($migration, 'CREATE TRIGGER trg_financial_transactions_validate_insert')
        && str_contains($migration, 'FROM financial_accounts fa')
        && str_contains($migration, 'reversed_transaction_id'),
);
equity_open_ref_assert(
    'SQLite path does not permanently drop immutability triggers',
    ! str_contains($migration, 'trg_financial_transactions_immutable_update')
        && ! str_contains($migration, 'trg_financial_transactions_immutable_delete'),
);
equity_open_ref_assert(
    'UNIQUE limitation is documented in the migration',
    str_contains($migration, 'does not independently prevent multiple opening journals for one account'),
);
equity_open_ref_assert(
    'architecture documents UNIQUE vs unreferenced journals and NULL zero/legacy',
    str_contains($architecture, 'opening_journal_entry_id')
        && str_contains($architecture, 'does not independently prevent multiple opening journals for one account')
        && str_contains($architecture, 'Unreferenced opening journals')
        && str_contains($architecture, 'legacy accounts and for a zero starting amount'),
);
equity_open_ref_assert(
    'FinancialAccount fillable does not include opening_journal_entry_id',
    str_contains($model, "'is_archived'")
        && ! str_contains($model, 'opening_journal_entry_id'),
);

echo "\n{$passed} passed, {$failed} failed\n";
echo "Scope: source inspection only. The migration was not executed.\n";

if ($failed > 0) {
    fwrite(STDERR, "\nFailed cases:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

exit(0);
