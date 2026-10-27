<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        // On SQLite, adding a foreign key via Schema::table rebuilds `ledger_accounts` by dropping and renaming
        // a temporary table. Any trigger on another table that references `ledger_accounts` must be dropped
        // during the table rebuild and recreated immediately afterward.
        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_journal_postings_validate_insert');
        }

        Schema::table('ledger_accounts', function (Blueprint $table): void {
            $table->unsignedBigInteger('category_id')->nullable()->after('financial_account_id');

            $table->foreign(['category_id', 'user_id'], 'ledger_accounts_category_id_user_id_foreign')
                ->references(['id', 'user_id'])
                ->on('categories')
                ->restrictOnDelete();

            $table->unique(['category_id', 'currency_code'], 'ledger_accounts_category_id_currency_code_unique');
        });

        if ($driver === 'sqlite') {
            $this->createSqliteJournalPostingsValidateTrigger();

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER trg_ledger_accounts_validate_category_insert
                BEFORE INSERT ON ledger_accounts
                BEGIN
                    SELECT CASE
                        WHEN NEW.financial_account_id IS NOT NULL AND NEW.category_id IS NOT NULL
                        THEN RAISE(ABORT, 'A ledger account cannot link to both a financial account and a category.')
                        WHEN NEW.category_id IS NOT NULL AND (
                            SELECT COUNT(*)
                            FROM categories c
                            WHERE c.id = NEW.category_id
                              AND c.user_id = NEW.user_id
                              AND c.type = NEW.classification
                        ) = 0
                        THEN RAISE(ABORT, 'Category-backed ledger account classification and owner must match its category.')
                    END;
                END;
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER trg_ledger_accounts_validate_category_update
                BEFORE UPDATE ON ledger_accounts
                BEGIN
                    SELECT CASE
                        WHEN (OLD.category_id IS NOT NEW.category_id)
                        THEN RAISE(ABORT, 'Ledger account category mapping cannot be modified.')
                        WHEN (OLD.financial_account_id IS NOT NEW.financial_account_id)
                        THEN RAISE(ABORT, 'Ledger account financial account mapping cannot be modified.')
                        WHEN NEW.category_id IS NOT NULL AND (
                            SELECT COUNT(*)
                            FROM categories c
                            WHERE c.id = NEW.category_id
                              AND c.user_id = NEW.user_id
                              AND c.type = NEW.classification
                        ) = 0
                        THEN RAISE(ABORT, 'Category-backed ledger account classification and owner must match its category.')
                    END;
                END;
            SQL);
        } elseif (in_array($driver, ['mariadb', 'mysql'], true)) {
            DB::statement(
                'ALTER TABLE ledger_accounts ADD CONSTRAINT chk_ledger_accounts_single_parent CHECK (NOT (financial_account_id IS NOT NULL AND category_id IS NOT NULL))',
            );

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER trg_ledger_accounts_validate_category_insert
                BEFORE INSERT ON ledger_accounts
                FOR EACH ROW
                BEGIN
                    IF NEW.financial_account_id IS NOT NULL AND NEW.category_id IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'A ledger account cannot link to both a financial account and a category.';
                    END IF;

                    IF NEW.category_id IS NOT NULL AND NOT EXISTS (
                        SELECT 1
                        FROM categories c
                        WHERE c.id = NEW.category_id
                          AND c.user_id = NEW.user_id
                          AND c.type = NEW.classification
                    ) THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Category-backed ledger account classification and owner must match its category.';
                    END IF;
                END;
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER trg_ledger_accounts_validate_category_update
                BEFORE UPDATE ON ledger_accounts
                FOR EACH ROW
                BEGIN
                    IF NOT (OLD.category_id <=> NEW.category_id) THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Ledger account category mapping cannot be modified.';
                    END IF;

                    IF NOT (OLD.financial_account_id <=> NEW.financial_account_id) THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Ledger account financial account mapping cannot be modified.';
                    END IF;

                    IF NEW.category_id IS NOT NULL AND NOT EXISTS (
                        SELECT 1
                        FROM categories c
                        WHERE c.id = NEW.category_id
                          AND c.user_id = NEW.user_id
                          AND c.type = NEW.classification
                    ) THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Category-backed ledger account classification and owner must match its category.';
                    END IF;
                END;
            SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['sqlite', 'mariadb', 'mysql'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_ledger_accounts_validate_category_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_ledger_accounts_validate_category_update');
        }

        if (in_array($driver, ['mariadb', 'mysql'], true)) {
            DB::statement('ALTER TABLE ledger_accounts DROP CONSTRAINT chk_ledger_accounts_single_parent');
        }

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_journal_postings_validate_insert');
        }

        Schema::table('ledger_accounts', function (Blueprint $table) use ($driver): void {
            if ($driver === 'sqlite') {
                $table->dropForeign(['category_id', 'user_id']);
            } else {
                $table->dropForeign('ledger_accounts_category_id_user_id_foreign');
            }

            $table->dropUnique('ledger_accounts_category_id_currency_code_unique');
            $table->dropColumn('category_id');
        });

        if ($driver === 'sqlite' && Schema::hasTable('journal_postings')) {
            $this->createSqliteJournalPostingsValidateTrigger();
        }
    }

    private function createSqliteJournalPostingsValidateTrigger(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_journal_postings_validate_insert
            BEFORE INSERT ON journal_postings
            BEGIN
                SELECT CASE
                    WHEN (
                        SELECT COUNT(*)
                        FROM journal_entries je
                        INNER JOIN ledger_accounts la ON la.id = NEW.ledger_account_id
                        WHERE je.id = NEW.journal_entry_id
                          AND je.user_id = la.user_id
                          AND je.currency_code = la.currency_code
                    ) = 0
                    THEN RAISE(ABORT, 'Journal posting ledger account must match journal entry owner and currency.')
                END;
            END;
        SQL);
    }
};
