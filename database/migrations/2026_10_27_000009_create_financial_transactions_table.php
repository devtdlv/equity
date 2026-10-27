<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHECK_EXPRESSION = 'amount_minor > 0 '
        .'AND amount_minor <= 1000000000000000000 '
        ."AND type IN ('income', 'expense', 'transfer') "
        ."AND ((type IN ('income', 'expense') AND category_id IS NOT NULL AND transfer_financial_account_id IS NULL) "
        ."OR (type = 'transfer' AND category_id IS NULL AND transfer_financial_account_id IS NOT NULL AND transfer_financial_account_id <> financial_account_id))";

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->unique(
                ['id', 'user_id', 'currency_code'],
                'journal_entries_id_user_id_currency_code_unique',
            );
        });

        Schema::create('financial_transactions', function (Blueprint $table) use ($driver): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('journal_entry_id')->unique('financial_transactions_journal_entry_id_unique');
            $table->unsignedBigInteger('financial_account_id');
            $table->unsignedBigInteger('transfer_financial_account_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('type', 16);
            $table->char('currency_code', 3);

            if ($driver === 'sqlite') {
                $table->rawColumn(
                    'amount_minor',
                    'integer not null check ('.self::CHECK_EXPRESSION.')',
                );
            } else {
                $table->bigInteger('amount_minor');
            }

            $table->date('entry_date');
            $table->string('description', 255);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('currency_code', 'financial_transactions_currency_code_foreign')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();

            $table->foreign(
                ['journal_entry_id', 'user_id', 'currency_code'],
                'financial_transactions_journal_entry_tenant_currency_foreign',
            )
                ->references(['id', 'user_id', 'currency_code'])
                ->on('journal_entries')
                ->restrictOnDelete();

            $table->foreign(
                ['financial_account_id', 'user_id'],
                'financial_transactions_account_user_foreign',
            )
                ->references(['id', 'user_id'])
                ->on('financial_accounts')
                ->restrictOnDelete();

            $table->foreign(
                ['transfer_financial_account_id', 'user_id'],
                'financial_transactions_transfer_account_user_foreign',
            )
                ->references(['id', 'user_id'])
                ->on('financial_accounts')
                ->restrictOnDelete();

            $table->foreign(
                ['category_id', 'user_id'],
                'financial_transactions_category_user_foreign',
            )
                ->references(['id', 'user_id'])
                ->on('categories')
                ->restrictOnDelete();

            $table->index(['user_id', 'entry_date', 'id'], 'financial_transactions_user_date_id_index');
            $table->index(
                ['user_id', 'financial_account_id', 'entry_date'],
                'financial_transactions_user_account_date_index',
            );
            $table->index(
                ['user_id', 'transfer_financial_account_id', 'entry_date'],
                'financial_transactions_user_transfer_account_date_index',
            );
            $table->index(
                ['user_id', 'category_id', 'entry_date'],
                'financial_transactions_user_category_date_index',
            );
        });

        if (in_array($driver, ['mariadb', 'mysql', 'pgsql'], true)) {
            DB::statement(
                'ALTER TABLE financial_transactions ADD CONSTRAINT chk_financial_transactions_integrity CHECK ('.self::CHECK_EXPRESSION.')',
            );
        }

        if ($driver === 'sqlite') {
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
        } elseif (in_array($driver, ['mariadb', 'mysql'], true)) {
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

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER trg_financial_transactions_immutable_update
                BEFORE UPDATE ON financial_transactions
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted financial transactions are immutable.';
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER trg_financial_transactions_immutable_delete
                BEFORE DELETE ON financial_transactions
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted financial transactions cannot be deleted.';
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
            DB::unprepared('DROP TRIGGER IF EXISTS trg_financial_transactions_validate_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_financial_transactions_immutable_update');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_financial_transactions_immutable_delete');
        }

        Schema::dropIfExists('financial_transactions');

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropUnique('journal_entries_id_user_id_currency_code_unique');
        });
    }
};
