<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HTTP create-account idempotency on financial_accounts.
 *
 * Adds nullable idempotency_key (UUID) and create_request_fingerprint (SHA-256 hex)
 * plus UNIQUE (user_id, idempotency_key). New creates always supply both via
 * FinancialAccountService. Legacy rows keep NULL keys; MariaDB/MySQL/SQLite allow
 * multiple NULLs in a unique index, so existing accounts remain valid without
 * fabricating fingerprints that cannot be reconstructed.
 *
 * On SQLite, Schema::table rebuilds financial_accounts; drop/recreate the
 * financial_transactions insert trigger that SELECTs from financial_accounts
 * (same pattern as migration 000015).
 */
return new class extends Migration
{
    public const IDEMPOTENCY_UNIQUE_INDEX = 'financial_accounts_user_id_idempotency_key_unique';

    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $this->assertSupportedDriver($driver);

        if (! Schema::hasTable('financial_accounts')) {
            throw new RuntimeException(
                'Cannot add create idempotency columns because financial_accounts does not exist.',
            );
        }

        if (Schema::hasColumn('financial_accounts', 'idempotency_key')) {
            return;
        }

        if ($driver === 'sqlite') {
            $this->dropFinancialTransactionInsertTrigger();
        }

        Schema::table('financial_accounts', function (Blueprint $table): void {
            $table->uuid('idempotency_key')->nullable()->after('user_id');
            $table->char('create_request_fingerprint', 64)->nullable()->after('idempotency_key');

            $table->unique(
                ['user_id', 'idempotency_key'],
                self::IDEMPOTENCY_UNIQUE_INDEX,
            );
        });

        if ($driver === 'sqlite') {
            $this->createSqliteFinancialTransactionInsertTrigger();
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $this->assertSupportedDriver($driver);

        if (! Schema::hasTable('financial_accounts')) {
            return;
        }

        if (! Schema::hasColumn('financial_accounts', 'idempotency_key')) {
            return;
        }

        if ($driver === 'sqlite') {
            $this->dropFinancialTransactionInsertTrigger();
        }

        Schema::table('financial_accounts', function (Blueprint $table): void {
            $table->dropUnique(self::IDEMPOTENCY_UNIQUE_INDEX);
            $table->dropColumn(['create_request_fingerprint', 'idempotency_key']);
        });

        if ($driver === 'sqlite') {
            $this->createSqliteFinancialTransactionInsertTrigger();
        }
    }

    private function dropFinancialTransactionInsertTrigger(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_financial_transactions_validate_insert');
    }

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
                'Unsupported database driver [%s] for financial account create-idempotency migration. Supported drivers: sqlite, mariadb, mysql.',
                $driver,
            ));
        }
    }
};
