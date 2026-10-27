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
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 64);
            $table->string('auditable_type', 128);
            $table->unsignedBigInteger('auditable_id');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['user_id', 'created_at']);
            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['user_id', 'action']);
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER trg_audit_events_immutable_update
                BEFORE UPDATE ON audit_events
                BEGIN
                    SELECT RAISE(ABORT, 'Audit events are append-only and cannot be modified.');
                END;
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER trg_audit_events_immutable_delete
                BEFORE DELETE ON audit_events
                BEGIN
                    SELECT RAISE(ABORT, 'Audit events are append-only and cannot be deleted.');
                END;
            SQL);
        } elseif (in_array($driver, ['mariadb', 'mysql'], true)) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER trg_audit_events_immutable_update
                BEFORE UPDATE ON audit_events
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit events are append-only and cannot be modified.';
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER trg_audit_events_immutable_delete
                BEFORE DELETE ON audit_events
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit events are append-only and cannot be deleted.';
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
            DB::unprepared('DROP TRIGGER IF EXISTS trg_audit_events_immutable_update');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_audit_events_immutable_delete');
        }

        Schema::dropIfExists('audit_events');
    }
};
