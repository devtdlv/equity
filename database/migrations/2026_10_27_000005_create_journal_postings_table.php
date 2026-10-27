<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHECK_EXPRESSION = 'debit_amount_minor >= 0 '
        .'AND credit_amount_minor >= 0 '
        .'AND debit_amount_minor <= 1000000000000000000 '
        .'AND credit_amount_minor <= 1000000000000000000 '
        .'AND ((debit_amount_minor > 0 AND credit_amount_minor = 0) OR (credit_amount_minor > 0 AND debit_amount_minor = 0))';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::create('journal_postings', function (Blueprint $table) use ($driver): void {
            $table->id();
            $table->foreignId('journal_entry_id')
                ->constrained('journal_entries')
                ->restrictOnDelete();
            $table->foreignId('ledger_account_id')
                ->constrained('ledger_accounts')
                ->restrictOnDelete();
            $table->bigInteger('debit_amount_minor')->default(0);

            if ($driver === 'sqlite') {
                $table->rawColumn(
                    'credit_amount_minor',
                    'integer not null default 0 check ('.self::CHECK_EXPRESSION.')',
                );
            } else {
                $table->bigInteger('credit_amount_minor')->default(0);
            }

            $table->timestamps();

            $table->index(['ledger_account_id', 'journal_entry_id']);
        });

        if (in_array($driver, ['mariadb', 'mysql', 'pgsql'], true)) {
            DB::statement(
                'ALTER TABLE journal_postings ADD CONSTRAINT chk_journal_postings_amounts CHECK ('.self::CHECK_EXPRESSION.')',
            );
        }

        if ($driver === 'sqlite') {
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

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER trg_journal_postings_immutable_update
                BEFORE UPDATE ON journal_postings
                BEGIN
                    SELECT RAISE(ABORT, 'Posted journal postings are immutable.');
                END;
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER trg_journal_postings_immutable_delete
                BEFORE DELETE ON journal_postings
                BEGIN
                    SELECT RAISE(ABORT, 'Posted journal postings cannot be deleted.');
                END;
            SQL);
        } elseif (in_array($driver, ['mariadb', 'mysql'], true)) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER trg_journal_postings_validate_insert
                BEFORE INSERT ON journal_postings
                FOR EACH ROW
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1
                        FROM journal_entries je
                        INNER JOIN ledger_accounts la ON la.id = NEW.ledger_account_id
                        WHERE je.id = NEW.journal_entry_id
                          AND je.user_id = la.user_id
                          AND je.currency_code = la.currency_code
                    ) THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Journal posting ledger account must match journal entry owner and currency.';
                    END IF;
                END;
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER trg_journal_postings_immutable_update
                BEFORE UPDATE ON journal_postings
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal postings are immutable.';
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER trg_journal_postings_immutable_delete
                BEFORE DELETE ON journal_postings
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal postings cannot be deleted.';
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
            DB::unprepared('DROP TRIGGER IF EXISTS trg_journal_postings_validate_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_journal_postings_immutable_update');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_journal_postings_immutable_delete');
        }

        Schema::dropIfExists('journal_postings');
    }
};
