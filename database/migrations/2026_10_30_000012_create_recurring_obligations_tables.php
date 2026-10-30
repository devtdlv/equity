<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Obligation integrity: money bounds, frequency, interval, anchor day, status/timestamp consistency.
     */
    public const OBLIGATION_CHECK = 'amount_minor > 0 '
        .'AND amount_minor <= 1000000000000000000 '
        ."AND category_type = 'expense' "
        ."AND frequency IN ('weekly', 'biweekly', 'monthly', 'quarterly', 'yearly') "
        .'AND interval_count = 1 '
        .'AND anchor_day >= 1 AND anchor_day <= 31 '
        ."AND status IN ('active', 'paused', 'cancelled') "
        ."AND ((status = 'active' AND paused_at IS NULL AND cancelled_at IS NULL) "
        ."OR (status = 'paused' AND paused_at IS NOT NULL AND cancelled_at IS NULL) "
        ."OR (status = 'cancelled' AND cancelled_at IS NOT NULL))";

    /**
     * Occurrence integrity including active_slot_key occupancy rules.
     *
     * Historical identity strategy:
     * - active_slot_key = due_date while status is scheduled or skipped (slot occupied).
     * - active_slot_key IS NULL when status is cancelled (slot released for deliberate reschedule).
     * - UNIQUE(obligation_id, active_slot_key) allows multiple NULLs (cancelled history) while
     *   permitting only one live occupant per due date (scheduled including paid/partial, or skipped).
     * Paid is a derived status; paid rows remain status=scheduled and therefore keep the slot.
     */
    public const OCCURRENCE_CHECK = 'expected_amount_minor > 0 '
        .'AND expected_amount_minor <= 1000000000000000000 '
        ."AND status IN ('scheduled', 'skipped', 'cancelled') "
        ."AND ((status = 'scheduled' AND skipped_at IS NULL AND cancelled_at IS NULL "
        .'AND active_slot_key IS NOT NULL AND active_slot_key = due_date) '
        ."OR (status = 'skipped' AND skipped_at IS NOT NULL AND cancelled_at IS NULL "
        .'AND active_slot_key IS NOT NULL AND active_slot_key = due_date) '
        ."OR (status = 'cancelled' AND cancelled_at IS NOT NULL AND active_slot_key IS NULL))";

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $this->assertSupportedDriver($driver);

        // Supports composite FK (financial_account_id, user_id, currency_code) from recurring_obligations.
        Schema::table('financial_accounts', function (Blueprint $table): void {
            $table->unique(
                ['id', 'user_id', 'currency_code'],
                'financial_accounts_id_user_id_currency_code_unique',
            );
        });

        // Supports composite FK (financial_transaction_id, user_id) from recurring_payment_links.
        // Intentionally independent of financial_transactions_id_user_id_currency_code_unique from
        // migration 000010 so reversal-migration rollback tests remain valid.
        Schema::table('financial_transactions', function (Blueprint $table): void {
            $table->unique(
                ['id', 'user_id'],
                'financial_transactions_id_user_id_unique',
            );
        });

        // categories_id_user_id_type_unique already exists from migration 000011.

        Schema::create('recurring_obligations', function (Blueprint $table) use ($driver): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->char('currency_code', 3);

            if ($driver === 'sqlite') {
                $table->rawColumn(
                    'amount_minor',
                    'integer not null check ('.self::OBLIGATION_CHECK.')',
                );
            } else {
                $table->bigInteger('amount_minor');
            }

            $table->unsignedBigInteger('category_id');
            $table->string('category_type', 16);
            $table->unsignedBigInteger('financial_account_id')->nullable();
            $table->date('start_date');
            $table->string('frequency', 16);
            $table->unsignedTinyInteger('interval_count')->default(1);
            $table->unsignedTinyInteger('anchor_day');
            $table->boolean('ends_of_month')->default(false);
            $table->date('next_occurrence_date')->nullable();
            $table->string('status', 16);
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->foreign('currency_code', 'recurring_obligations_currency_code_foreign')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();

            $table->foreign(
                ['category_id', 'user_id', 'category_type'],
                'recurring_obligations_category_user_type_foreign',
            )
                ->references(['id', 'user_id', 'type'])
                ->on('categories')
                ->restrictOnDelete();

            $table->foreign(
                ['financial_account_id', 'user_id', 'currency_code'],
                'recurring_obligations_account_user_currency_foreign',
            )
                ->references(['id', 'user_id', 'currency_code'])
                ->on('financial_accounts')
                ->restrictOnDelete();

            $table->unique(['id', 'user_id'], 'recurring_obligations_id_user_id_unique');
            $table->unique(
                ['id', 'user_id', 'currency_code'],
                'recurring_obligations_id_user_id_currency_code_unique',
            );
            $table->unique(
                ['user_id', 'idempotency_key'],
                'recurring_obligations_user_id_idempotency_key_unique',
            );
            $table->index(
                ['user_id', 'status', 'next_occurrence_date'],
                'recurring_obligations_user_status_next_date_index',
            );
            $table->index(
                ['user_id', 'currency_code'],
                'recurring_obligations_user_id_currency_code_index',
            );
        });

        if (in_array($driver, ['mariadb', 'mysql'], true)) {
            DB::statement(
                'ALTER TABLE recurring_obligations ADD CONSTRAINT chk_recurring_obligations_integrity CHECK ('
                .self::OBLIGATION_CHECK.')',
            );
        }

        Schema::create('recurring_occurrences', function (Blueprint $table) use ($driver): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('obligation_id');
            $table->date('due_date');

            if ($driver === 'sqlite') {
                $table->rawColumn(
                    'expected_amount_minor',
                    'integer not null check ('.self::OCCURRENCE_CHECK.')',
                );
            } else {
                $table->bigInteger('expected_amount_minor');
            }

            $table->char('currency_code', 3);
            $table->string('status', 16);
            // Occupancy key for current schedule slots; NULL releases the slot after cancel.
            $table->date('active_slot_key')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->foreign(
                ['obligation_id', 'user_id', 'currency_code'],
                'recurring_occurrences_obligation_user_currency_foreign',
            )
                ->references(['id', 'user_id', 'currency_code'])
                ->on('recurring_obligations')
                ->restrictOnDelete();

            $table->unique(['id', 'user_id'], 'recurring_occurrences_id_user_id_unique');
            $table->unique(
                ['id', 'user_id', 'currency_code'],
                'recurring_occurrences_id_user_id_currency_code_unique',
            );
            // At most one live scheduled/skipped occupant per obligation due date.
            $table->unique(
                ['obligation_id', 'active_slot_key'],
                'recurring_occurrences_obligation_id_active_slot_key_unique',
            );
            $table->index(
                ['user_id', 'due_date'],
                'recurring_occurrences_user_id_due_date_index',
            );
            $table->index(
                ['obligation_id', 'status', 'due_date'],
                'recurring_occurrences_obligation_status_due_date_index',
            );
        });

        if (in_array($driver, ['mariadb', 'mysql'], true)) {
            DB::statement(
                'ALTER TABLE recurring_occurrences ADD CONSTRAINT chk_recurring_occurrences_integrity CHECK ('
                .self::OCCURRENCE_CHECK.')',
            );
        }

        Schema::create('recurring_payment_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('occurrence_id');
            $table->unsignedBigInteger('financial_transaction_id');
            // Informative denormalized currency for service-layer matching; not a cross-table FX FK.
            $table->char('currency_code', 3);
            $table->timestamps();

            $table->foreign(
                ['occurrence_id', 'user_id'],
                'recurring_payment_links_occurrence_user_foreign',
            )
                ->references(['id', 'user_id'])
                ->on('recurring_occurrences')
                ->restrictOnDelete();

            $table->foreign(
                ['financial_transaction_id', 'user_id'],
                'recurring_payment_links_transaction_user_foreign',
            )
                ->references(['id', 'user_id'])
                ->on('financial_transactions')
                ->restrictOnDelete();

            $table->unique(
                'financial_transaction_id',
                'recurring_payment_links_financial_transaction_id_unique',
            );
            $table->unique(
                ['occurrence_id', 'financial_transaction_id'],
                'recurring_payment_links_occurrence_transaction_unique',
            );
            $table->index(
                ['user_id', 'occurrence_id'],
                'recurring_payment_links_user_id_occurrence_id_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $this->assertSupportedDriver($driver);

        Schema::dropIfExists('recurring_payment_links');
        Schema::dropIfExists('recurring_occurrences');
        Schema::dropIfExists('recurring_obligations');

        Schema::table('financial_transactions', function (Blueprint $table): void {
            $table->dropUnique('financial_transactions_id_user_id_unique');
        });

        Schema::table('financial_accounts', function (Blueprint $table): void {
            $table->dropUnique('financial_accounts_id_user_id_currency_code_unique');
        });
    }

    private function assertSupportedDriver(string $driver): void
    {
        if (! in_array($driver, ['sqlite', 'mariadb', 'mysql'], true)) {
            throw new RuntimeException(sprintf(
                'Unsupported database driver [%s] for recurring obligations migration. Supported drivers: sqlite, mariadb, mysql.',
                $driver,
            ));
        }
    }
};
