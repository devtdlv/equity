<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Durable create-idempotency fingerprint for savings goals.
 *
 * Step 3 requires payload equivalence that survives later edits to mutable
 * fields (name, description, target). Comparing against live columns would
 * misclassify legitimate UUID retries after update (test K).
 *
 * Fail-closed: refuse to ALTER when savings_goals already has rows. MariaDB
 * may accept NOT NULL CHAR(64) without a default and silently store empty
 * strings for existing rows, which destroys durable create-idempotency.
 * Original creation payloads cannot be reconstructed safely, so fingerprints
 * must never be fabricated. Apply only when savings_goals is empty
 * (typically immediately after 000013, before any goals are created).
 *
 * Rollback removes fingerprint data. Reapplication after rollback also
 * refuses when goals still exist and will not recreate empty fingerprints.
 *
 * No portable hex-format CHECK is added: MariaDB REGEXP and SQLite CHECK
 * syntax diverge, so format enforcement remains in SavingsGoalService.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('savings_goals')) {
            throw new RuntimeException(
                'Cannot add create_request_fingerprint because savings_goals does not exist. '
                .'Run migration 000013 first.',
            );
        }

        if (Schema::hasColumn('savings_goals', 'create_request_fingerprint')) {
            return;
        }

        // Guard must run before any DDL. MariaDB DDL may implicitly commit,
        // so wrapping ALTER in a transaction cannot roll back an unsafe add.
        if (DB::table('savings_goals')->exists()) {
            throw new RuntimeException(
                'Cannot add create_request_fingerprint while savings_goals contains existing rows. '
                .'Original creation payloads cannot be reconstructed safely, so fingerprints '
                .'must not be fabricated. Apply this migration only when savings_goals is empty '
                .'(typically immediately after 000013, before any goals are created).',
            );
        }

        Schema::table('savings_goals', function (Blueprint $table): void {
            $table->char('create_request_fingerprint', 64)->after('idempotency_key');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('savings_goals')) {
            return;
        }

        if (! Schema::hasColumn('savings_goals', 'create_request_fingerprint')) {
            return;
        }

        Schema::table('savings_goals', function (Blueprint $table): void {
            $table->dropColumn('create_request_fingerprint');
        });
    }
};
