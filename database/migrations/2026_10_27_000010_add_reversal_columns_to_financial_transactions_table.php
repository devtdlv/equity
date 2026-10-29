<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public const ORIGINAL_CHECK_EXPRESSION = 'amount_minor > 0 '
        .'AND amount_minor <= 1000000000000000000 '
        ."AND type IN ('income', 'expense', 'transfer') "
        ."AND ((type IN ('income', 'expense') AND category_id IS NOT NULL AND transfer_financial_account_id IS NULL) "
        ."OR (type = 'transfer' AND category_id IS NULL AND transfer_financial_account_id IS NOT NULL AND transfer_financial_account_id <> financial_account_id))";

    public const CHECK_EXPRESSION = self::ORIGINAL_CHECK_EXPRESSION.' '
        .'AND ((reversed_transaction_id IS NULL AND reversal_reason IS NULL) '
        .'OR (reversed_transaction_id IS NOT NULL AND reversed_transaction_id > 0))';

    /**
     * Run the migrations.
     *
     * Note: MariaDB and MySQL execute DDL statements with implicit commits (non-transactional DDL).
     * To minimize exposure windows on MariaDB/MySQL:
     * 1. Existing immutability triggers (BEFORE UPDATE and BEFORE DELETE) are never dropped.
     * 2. Additive columns, unique indexes, and foreign keys are added while the original CHECK
     *    constraint and BEFORE INSERT trigger remain active.
     * 3. The table CHECK constraint is replaced in a single ALTER TABLE statement, and only the
     *    BEFORE INSERT validation trigger is replaced afterward.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $this->assertSupportedDriver($driver);

        if ($driver === 'sqlite') {
            // SQLite rebuilds the table during Schema::table when adding foreign keys;
            // drop the table's triggers immediately before the single rebuild and recreate them right after.
            $this->dropAllFinancialTransactionTriggers();

            Schema::table('financial_transactions', function (Blueprint $table): void {
                $table->unsignedBigInteger('reversed_transaction_id')
                    ->nullable()
                    ->after('journal_entry_id');

                $table->rawColumn(
                    'reversal_reason',
                    'varchar(255) check ('.self::CHECK_EXPRESSION.')',
                )->nullable()->after('notes');

                $table->unique(
                    ['id', 'user_id', 'currency_code'],
                    'financial_transactions_id_user_id_currency_code_unique',
                );

                $table->unique(
                    'reversed_transaction_id',
                    'financial_transactions_reversed_transaction_id_unique',
                );

                $table->foreign(
                    ['reversed_transaction_id', 'user_id', 'currency_code'],
                    'financial_transactions_reversed_tx_tenant_currency_foreign',
                )
                    ->references(['id', 'user_id', 'currency_code'])
                    ->on('financial_transactions')
                    ->restrictOnDelete();
            });

            $this->createSqliteTriggersWithReversalValidation();

            return;
        }

        // MariaDB / MySQL path: keep existing triggers and CHECK constraint active while adding columns and keys.
        Schema::table('financial_transactions', function (Blueprint $table): void {
            $table->unique(
                ['id', 'user_id', 'currency_code'],
                'financial_transactions_id_user_id_currency_code_unique',
            );
        });

        Schema::table('financial_transactions', function (Blueprint $table): void {
            $table->unsignedBigInteger('reversed_transaction_id')
                ->nullable()
                ->after('journal_entry_id');

            $table->string('reversal_reason', 255)
                ->nullable()
                ->after('notes');

            $table->unique(
                'reversed_transaction_id',
                'financial_transactions_reversed_transaction_id_unique',
            );

            $table->foreign(
                ['reversed_transaction_id', 'user_id', 'currency_code'],
                'financial_transactions_reversed_tx_tenant_currency_foreign',
            )
                ->references(['id', 'user_id', 'currency_code'])
                ->on('financial_transactions')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE financial_transactions '
            .'DROP CONSTRAINT chk_financial_transactions_integrity, '
            .'ADD CONSTRAINT chk_financial_transactions_integrity CHECK ('.self::CHECK_EXPRESSION.')',
        );

        $this->replaceMariaDbInsertTriggerWithReversalValidation();
    }

    /**
     * Reverse the migrations.
     *
     * Refuses to roll back if any reversal transactions exist so historical reversal audit trails
     * and links cannot be silently destroyed or orphaned.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $this->assertSupportedDriver($driver);
        $this->assertNoReversalTransactionsExist();

        if ($driver === 'sqlite') {
            $this->dropAllFinancialTransactionTriggers();

            Schema::table('financial_transactions', function (Blueprint $table): void {
                $table->dropForeign(['reversed_transaction_id', 'user_id', 'currency_code']);
                $table->dropUnique('financial_transactions_reversed_transaction_id_unique');
                $table->dropColumn(['reversed_transaction_id', 'reversal_reason']);
                $table->dropUnique('financial_transactions_id_user_id_currency_code_unique');
            });

            $this->createOriginalSqliteTriggers();

            return;
        }

        // MariaDB / MySQL path:
        // 1. Restore the original BEFORE INSERT trigger first (BEFORE UPDATE/DELETE triggers remain untouched).
        $this->replaceMariaDbInsertTriggerWithOriginalValidation();

        // 2. Restore the original CHECK constraint in a single ALTER TABLE statement before dropping the columns.
        DB::statement(
            'ALTER TABLE financial_transactions '
            .'DROP CONSTRAINT chk_financial_transactions_integrity, '
            .'ADD CONSTRAINT chk_financial_transactions_integrity CHECK ('.self::ORIGINAL_CHECK_EXPRESSION.')',
        );

        // 3. Drop the reversal foreign key, its auto-created MariaDB/MySQL backing index, unique indexes, and columns.
        Schema::table('financial_transactions', function (Blueprint $table): void {
            $table->dropForeign('financial_transactions_reversed_tx_tenant_currency_foreign');
            $table->dropIndex('financial_transactions_reversed_tx_tenant_currency_foreign');
            $table->dropUnique('financial_transactions_reversed_transaction_id_unique');
            $table->dropColumn(['reversed_transaction_id', 'reversal_reason']);
            $table->dropUnique('financial_transactions_id_user_id_currency_code_unique');
        });
    }

    private function assertSupportedDriver(string $driver): void
    {
        if (! in_array($driver, ['sqlite', 'mariadb', 'mysql'], true)) {
            throw new RuntimeException(sprintf(
                'Unsupported database driver [%s] for financial_transactions reversal migration. Supported drivers: sqlite, mariadb, mysql.',
                $driver,
            ));
        }
    }

    private function assertNoReversalTransactionsExist(): void
    {
        if (! Schema::hasTable('financial_transactions')) {
            return;
        }

        $hasReversalRows = DB::table('financial_transactions')
            ->where(function ($query): void {
                $query->whereNotNull('reversed_transaction_id')
                    ->orWhereNotNull('reversal_reason');
            })
            ->exists();

        if ($hasReversalRows) {
            throw new RuntimeException(
                'Cannot roll back financial_transactions reversal migration while reversal records exist.',
            );
        }
    }

    private function dropAllFinancialTransactionTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_financial_transactions_validate_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_financial_transactions_immutable_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_financial_transactions_immutable_delete');
    }

    private function createSqliteTriggersWithReversalValidation(): void
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

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_financial_transactions_immutable_update
            BEFORE UPDATE ON financial_transactions
            BEGIN
                SELECT RAISE(ABORT, 'Posted financial transactions are immutable.');
            END;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_financial_transactions_immutable_delete
            BEFORE DELETE ON financial_transactions
            BEGIN
                SELECT RAISE(ABORT, 'Posted financial transactions cannot be deleted.');
            END;
        SQL);
    }

    private function createOriginalSqliteTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_financial_transactions_validate_insert
            BEFORE INSERT ON financial_transactions
            BEGIN
                SELECT CASE
                    WHEN NOT (
                        NEW.amount_minor > 0
                        AND NEW.amount_minor <= 1000000000000000000
                        AND NEW.type IN ('income', 'expense', 'transfer')
                        AND (
                            (NEW.type IN ('income', 'expense') AND NEW.category_id IS NOT NULL AND NEW.transfer_financial_account_id IS NULL)
                            OR (NEW.type = 'transfer' AND NEW.category_id IS NULL AND NEW.transfer_financial_account_id IS NOT NULL AND NEW.transfer_financial_account_id <> NEW.financial_account_id)
                        )
                    )
                    THEN RAISE(ABORT, 'Financial transaction failed integrity check constraint.')
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
                END;
            END;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_financial_transactions_immutable_update
            BEFORE UPDATE ON financial_transactions
            BEGIN
                SELECT RAISE(ABORT, 'Posted financial transactions are immutable.');
            END;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_financial_transactions_immutable_delete
            BEFORE DELETE ON financial_transactions
            BEGIN
                SELECT RAISE(ABORT, 'Posted financial transactions cannot be deleted.');
            END;
        SQL);
    }

    private function replaceMariaDbInsertTriggerWithReversalValidation(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_financial_transactions_validate_insert');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_financial_transactions_validate_insert
            BEFORE INSERT ON financial_transactions
            FOR EACH ROW
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM financial_accounts fa
                    WHERE fa.id = NEW.financial_account_id
                      AND fa.user_id = NEW.user_id
                      AND fa.currency_code = NEW.currency_code
                ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Financial transaction currency and owner must match the primary financial account.';
                END IF;

                IF NEW.transfer_financial_account_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1
                    FROM financial_accounts tfa
                    WHERE tfa.id = NEW.transfer_financial_account_id
                      AND tfa.user_id = NEW.user_id
                      AND tfa.currency_code = NEW.currency_code
                ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Transfer financial account currency and owner must match the financial transaction.';
                END IF;

                IF NEW.category_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1
                    FROM categories c
                    WHERE c.id = NEW.category_id
                      AND c.user_id = NEW.user_id
                      AND c.type = NEW.type
                ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Financial transaction category must match the transaction owner and type.';
                END IF;

                IF NEW.reversed_transaction_id IS NOT NULL
                   AND NEW.id IS NOT NULL
                   AND NEW.id > 0
                   AND NEW.reversed_transaction_id = NEW.id THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'A financial transaction cannot reverse itself.';
                END IF;

                IF NEW.reversed_transaction_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1
                    FROM financial_transactions orig
                    WHERE orig.id = NEW.reversed_transaction_id
                      AND (NEW.id IS NULL OR NEW.id = 0 OR orig.id <> NEW.id)
                      AND orig.user_id = NEW.user_id
                      AND orig.currency_code = NEW.currency_code
                      AND orig.type = NEW.type
                      AND orig.amount_minor = NEW.amount_minor
                      AND orig.financial_account_id = NEW.financial_account_id
                      AND (orig.transfer_financial_account_id <=> NEW.transfer_financial_account_id)
                      AND (orig.category_id <=> NEW.category_id)
                      AND orig.reversed_transaction_id IS NULL
                ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Reversal transaction must reference a valid non-reversal original transaction with matching attributes.';
                END IF;
            END;
        SQL);
    }

    private function replaceMariaDbInsertTriggerWithOriginalValidation(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_financial_transactions_validate_insert');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_financial_transactions_validate_insert
            BEFORE INSERT ON financial_transactions
            FOR EACH ROW
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM financial_accounts fa
                    WHERE fa.id = NEW.financial_account_id
                      AND fa.user_id = NEW.user_id
                      AND fa.currency_code = NEW.currency_code
                ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Financial transaction currency and owner must match the primary financial account.';
                END IF;

                IF NEW.transfer_financial_account_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1
                    FROM financial_accounts tfa
                    WHERE tfa.id = NEW.transfer_financial_account_id
                      AND tfa.user_id = NEW.user_id
                      AND tfa.currency_code = NEW.currency_code
                ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Transfer financial account currency and owner must match the financial transaction.';
                END IF;

                IF NEW.category_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1
                    FROM categories c
                    WHERE c.id = NEW.category_id
                      AND c.user_id = NEW.user_id
                      AND c.type = NEW.type
                ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Financial transaction category must match the transaction owner and type.';
                END IF;
            END;
        SQL);
    }
};
