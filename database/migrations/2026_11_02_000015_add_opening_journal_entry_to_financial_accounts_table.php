<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nullable opening-journal pointer on financial_accounts.
 *
 * UNIQUE (opening_journal_entry_id) prevents two accounts from sharing one journal.
 * It does not independently prevent multiple opening journals for one account:
 * extra unreferenced journal_entries can still exist, and this column is not
 * protected by journal immutability triggers.
 *
 * The composite foreign key requires journal_entries(id, user_id, currency_code)
 * (unique index from migration 000009). MATCH SIMPLE allows a NULL pointer
 * while user_id and currency_code remain populated (legacy accounts and zero
 * amounts). No journals are created or backfilled.
 *
 * On SQLite, Schema::table with a foreign key rebuilds financial_accounts by
 * dropping/renaming a temporary table. Triggers on other tables that SELECT
 * from financial_accounts must be dropped for that rebuild and recreated
 * afterward (same pattern as migrations 000008 and 000010). MariaDB/MySQL
 * ALTER TABLE ADD COLUMN / ADD CONSTRAINT does not require that dance.
 */
return new class extends Migration
{
    public const UNIQUE_INDEX = 'financial_accounts_opening_journal_entry_id_unique';

    public const COMPOSITE_FOREIGN = 'financial_accounts_opening_journal_tenant_currency_foreign';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $this->assertSupportedDriver($driver);

        if (! Schema::hasTable('financial_accounts')) {
            throw new RuntimeException(
                'Cannot add opening_journal_entry_id because financial_accounts does not exist.',
            );
        }

        if (! Schema::hasTable('journal_entries')) {
            throw new RuntimeException(
                'Cannot add opening_journal_entry_id because journal_entries does not exist.',
            );
        }

        if (Schema::hasColumn('financial_accounts', 'opening_journal_entry_id')) {
            return;
        }

        if ($driver === 'sqlite') {
            $this->dropFinancialTransactionInsertTrigger();
        }

        Schema::table('financial_accounts', function (Blueprint $table): void {
            $table->unsignedBigInteger('opening_journal_entry_id')->nullable()->after('archived_at');

            $table->unique('opening_journal_entry_id', self::UNIQUE_INDEX);

            // Composite FK includes the journal id reference. A second FK on
            // opening_journal_entry_id alone would be redundant.
            $table->foreign(
                ['opening_journal_entry_id', 'user_id', 'currency_code'],
                self::COMPOSITE_FOREIGN,
            )
                ->references(['id', 'user_id', 'currency_code'])
                ->on('journal_entries')
                ->restrictOnDelete();
        });

        if ($driver === 'sqlite') {
            $this->createSqliteFinancialTransactionInsertTrigger();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $this->assertSupportedDriver($driver);

        if (! Schema::hasTable('financial_accounts')) {
            return;
        }

        if (! Schema::hasColumn('financial_accounts', 'opening_journal_entry_id')) {
            return;
        }

        if ($driver === 'sqlite') {
            $this->dropFinancialTransactionInsertTrigger();
        }

        Schema::table('financial_accounts', function (Blueprint $table): void {
            $table->dropForeign(self::COMPOSITE_FOREIGN);
            $table->dropUnique(self::UNIQUE_INDEX);
            $table->dropColumn('opening_journal_entry_id');
        });

        if ($driver === 'sqlite') {
            $this->createSqliteFinancialTransactionInsertTrigger();
        }
    }

    private function dropFinancialTransactionInsertTrigger(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_financial_transactions_validate_insert');
    }

    /**
     * Recreates the post-000010 SQLite BEFORE INSERT trigger for financial_transactions.
     *
     * Immutable UPDATE/DELETE triggers do not reference financial_accounts and are left alone.
     */
    private function createSqliteFinancialTransactionInsertTrigger(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_financial_transactions_validate_insert
            BEFORE INSERT ON financial_transactions
            BEGIN
                SELECT CASE
                    WHEN (
                        SELECT COUNT(*)
                        FROM financial_accounts fa
                        WHERE fa.id = NEW.financial_account_id
                          AND fa.user_id = NEW.user_id
                          AND fa.currency_code = NEW.currency_code
                    ) = 0
                    THEN RAISE(ABORT, 'Financial transaction currency and owner must match the primary financial account.')
                    WHEN NEW.transfer_financial_account_id IS NOT NULL AND (
                        SELECT COUNT(*)
                        FROM financial_accounts tfa
                        WHERE tfa.id = NEW.transfer_financial_account_id
                          AND tfa.user_id = NEW.user_id
                          AND tfa.currency_code = NEW.currency_code
                    ) = 0
                    THEN RAISE(ABORT, 'Transfer financial account currency and owner must match the financial transaction.')
                    WHEN NEW.category_id IS NOT NULL AND (
                        SELECT COUNT(*)
                        FROM categories c
                        WHERE c.id = NEW.category_id
                          AND c.user_id = NEW.user_id
                          AND c.type = NEW.type
                    ) = 0
                    THEN RAISE(ABORT, 'Financial transaction category must match the transaction owner and type.')
                    WHEN NEW.reversed_transaction_id IS NOT NULL
                         AND NEW.id IS NOT NULL
                         AND NEW.id > 0
                         AND NEW.reversed_transaction_id = NEW.id
                    THEN RAISE(ABORT, 'A financial transaction cannot reverse itself.')
                    WHEN NEW.reversed_transaction_id IS NOT NULL AND (
                        SELECT COUNT(*)
                        FROM financial_transactions orig
                        WHERE orig.id = NEW.reversed_transaction_id
                          AND (NEW.id IS NULL OR NEW.id <= 0 OR orig.id <> NEW.id)
                          AND orig.user_id = NEW.user_id
                          AND orig.currency_code = NEW.currency_code
                          AND orig.type = NEW.type
                          AND orig.amount_minor = NEW.amount_minor
                          AND orig.financial_account_id = NEW.financial_account_id
                          AND (orig.transfer_financial_account_id IS NEW.transfer_financial_account_id)
                          AND (orig.category_id IS NEW.category_id)
                          AND orig.reversed_transaction_id IS NULL
                    ) = 0
                    THEN RAISE(ABORT, 'Reversal transaction must reference a valid non-reversal original transaction with matching attributes.')
                END;
            END;
        SQL);
    }

    private function assertSupportedDriver(string $driver): void
    {
        if (! in_array($driver, ['sqlite', 'mariadb', 'mysql'], true)) {
            throw new RuntimeException(sprintf(
                'Unsupported database driver [%s] for opening journal reference migration. Supported drivers: sqlite, mariadb, mysql.',
                $driver,
            ));
        }
    }
};
