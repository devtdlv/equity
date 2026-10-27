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
        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->char('currency_code', 3);
            $table->string('idempotency_key', 128);
            $table->char('request_fingerprint', 64);
            $table->date('entry_date');
            $table->string('description', 255);
            $table->string('external_reference', 128)->nullable();
            $table->string('status', 32)->default('posted');
            $table->timestamp('posted_at');
            $table->timestamps();

            $table->foreign('currency_code')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();

            $table->unique(['user_id', 'idempotency_key'], 'journal_entries_user_id_idempotency_key_unique');
            $table->index(['user_id', 'entry_date']);
            $table->index(['user_id', 'currency_code']);
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER trg_journal_entries_immutable_update
                BEFORE UPDATE ON journal_entries
                BEGIN
                    SELECT RAISE(ABORT, 'Posted journal entries are immutable.');
                END;
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER trg_journal_entries_immutable_delete
                BEFORE DELETE ON journal_entries
                BEGIN
                    SELECT RAISE(ABORT, 'Posted journal entries cannot be deleted.');
                END;
            SQL);
        } elseif (in_array($driver, ['mariadb', 'mysql'], true)) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER trg_journal_entries_immutable_update
                BEFORE UPDATE ON journal_entries
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal entries are immutable.';
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER trg_journal_entries_immutable_delete
                BEFORE DELETE ON journal_entries
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal entries cannot be deleted.';
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
            DB::unprepared('DROP TRIGGER IF EXISTS trg_journal_entries_immutable_update');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_journal_entries_immutable_delete');
        }

        Schema::dropIfExists('journal_entries');
    }
};
