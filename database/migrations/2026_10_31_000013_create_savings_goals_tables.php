<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Goal integrity: positive target within Money::MAX_MINOR_AMOUNT, status/archive consistency.
     * Completion is derived from event sums and is intentionally not stored.
     */
    public const GOAL_CHECK = 'target_amount_minor > 0 '
        .'AND target_amount_minor <= 1000000000000000000 '
        ."AND status IN ('active', 'archived') "
        ."AND ((status = 'active' AND archived_at IS NULL) "
        ."OR (status = 'archived' AND archived_at IS NOT NULL))";

    /**
     * Event integrity: positive amount within Money::MAX_MINOR_AMOUNT; contribution or withdrawal only.
     * Sign for aggregation is derived from type in the service layer; amount_minor is always positive.
     */
    public const EVENT_CHECK = 'amount_minor > 0 '
        .'AND amount_minor <= 1000000000000000000 '
        ."AND type IN ('contribution', 'withdrawal')";

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $this->assertSupportedDriver($driver);

        Schema::create('savings_goals', function (Blueprint $table) use ($driver): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->char('currency_code', 3);

            if ($driver === 'sqlite') {
                $table->rawColumn(
                    'target_amount_minor',
                    'integer not null check ('.self::GOAL_CHECK.')',
                );
            } else {
                $table->bigInteger('target_amount_minor');
            }

            $table->date('target_date')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->foreign('currency_code', 'savings_goals_currency_code_foreign')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();

            // Supports composite FKs from savings_goal_events (goal + user + currency).
            $table->unique(['id', 'user_id'], 'savings_goals_id_user_id_unique');
            $table->unique(
                ['id', 'user_id', 'currency_code'],
                'savings_goals_id_user_id_currency_code_unique',
            );
            $table->unique(
                ['user_id', 'idempotency_key'],
                'savings_goals_user_id_idempotency_key_unique',
            );
            $table->index(
                ['user_id', 'status'],
                'savings_goals_user_id_status_index',
            );
            $table->index(
                ['user_id', 'currency_code'],
                'savings_goals_user_id_currency_code_index',
            );
        });

        if (in_array($driver, ['mariadb', 'mysql'], true)) {
            DB::statement(
                'ALTER TABLE savings_goals ADD CONSTRAINT chk_savings_goals_integrity CHECK ('
                .self::GOAL_CHECK.')',
            );
        }

        Schema::create('savings_goal_events', function (Blueprint $table) use ($driver): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('savings_goal_id');
            $table->uuid('idempotency_key');
            $table->string('type', 16);

            if ($driver === 'sqlite') {
                $table->rawColumn(
                    'amount_minor',
                    'integer not null check ('.self::EVENT_CHECK.')',
                );
            } else {
                $table->bigInteger('amount_minor');
            }

            $table->char('currency_code', 3);
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->foreign(
                ['savings_goal_id', 'user_id', 'currency_code'],
                'savings_goal_events_goal_user_currency_foreign',
            )
                ->references(['id', 'user_id', 'currency_code'])
                ->on('savings_goals')
                ->restrictOnDelete();

            $table->unique(
                ['user_id', 'idempotency_key'],
                'savings_goal_events_user_id_idempotency_key_unique',
            );
            $table->index(
                ['savings_goal_id', 'created_at'],
                'savings_goal_events_goal_id_created_at_index',
            );
            $table->index(
                ['savings_goal_id', 'type'],
                'savings_goal_events_goal_id_type_index',
            );
            $table->index(
                ['user_id', 'created_at'],
                'savings_goal_events_user_id_created_at_index',
            );
        });

        if (in_array($driver, ['mariadb', 'mysql'], true)) {
            DB::statement(
                'ALTER TABLE savings_goal_events ADD CONSTRAINT chk_savings_goal_events_integrity CHECK ('
                .self::EVENT_CHECK.')',
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $this->assertSupportedDriver($driver);

        Schema::dropIfExists('savings_goal_events');
        Schema::dropIfExists('savings_goals');
    }

    private function assertSupportedDriver(string $driver): void
    {
        if (! in_array($driver, ['sqlite', 'mariadb', 'mysql'], true)) {
            throw new RuntimeException(sprintf(
                'Unsupported database driver [%s] for savings goals migration. Supported drivers: sqlite, mariadb, mysql.',
                $driver,
            ));
        }
    }
};
