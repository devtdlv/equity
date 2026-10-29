<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Calendar-month validity for MariaDB / MySQL.
     *
     * period_start must be the first day of a month; period_end must be the last day
     * of that same month (LAST_DAY), and period_end must not precede period_start.
     */
    public const BUDGET_PERIOD_CHECK_MARIADB = 'DAYOFMONTH(period_start) = 1 '
        .'AND period_end = LAST_DAY(period_start) '
        .'AND period_end >= period_start';

    /**
     * Calendar-month validity for SQLite tests.
     */
    public const BUDGET_PERIOD_CHECK_SQLITE = "CAST(strftime('%d', period_start) AS INTEGER) = 1 "
        ."AND period_end = date(period_start, '+1 month', '-1 day') "
        .'AND period_end >= period_start';

    /**
     * Allocation amount bounds (integer minor units, zero allowed).
     */
    public const ALLOCATION_AMOUNT_CHECK = 'amount_minor >= 0 '
        .'AND amount_minor <= 1000000000000000000 '
        ."AND category_type = 'expense'";

    /**
     * Run the migrations.
     *
     * Expense-category enforcement: additive unique index on categories(id, user_id, type)
     * plus budget_allocations.category_type constrained to 'expense' and a composite FK
     * (category_id, user_id, category_type) -> categories(id, user_id, type). This works
     * identically on MariaDB and SQLite without driver-only triggers.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $this->assertSupportedDriver($driver);

        Schema::table('categories', function (Blueprint $table): void {
            $table->unique(
                ['id', 'user_id', 'type'],
                'categories_id_user_id_type_unique',
            );
        });

        Schema::create('budgets', function (Blueprint $table) use ($driver): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->char('currency_code', 3);

            if ($driver === 'sqlite') {
                $table->rawColumn('period_start', 'date not null');
                $table->rawColumn(
                    'period_end',
                    'date not null check ('.self::BUDGET_PERIOD_CHECK_SQLITE.')',
                );
            } else {
                $table->date('period_start');
                $table->date('period_end');
            }

            $table->timestamps();

            $table->foreign('currency_code', 'budgets_currency_code_foreign')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();

            $table->unique(['id', 'user_id'], 'budgets_id_user_id_unique');
            $table->unique(
                ['user_id', 'currency_code', 'period_start'],
                'budgets_user_currency_period_start_unique',
            );
            $table->index(['user_id', 'period_start'], 'budgets_user_id_period_start_index');
            $table->index(
                ['user_id', 'currency_code', 'period_end'],
                'budgets_user_id_currency_period_end_index',
            );
        });

        if (in_array($driver, ['mariadb', 'mysql'], true)) {
            DB::statement(
                'ALTER TABLE budgets ADD CONSTRAINT chk_budgets_calendar_month CHECK ('
                .self::BUDGET_PERIOD_CHECK_MARIADB.')',
            );
        }

        Schema::create('budget_allocations', function (Blueprint $table) use ($driver): void {
            $table->id();
            $table->unsignedBigInteger('budget_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('category_id');
            $table->string('category_type', 16);

            if ($driver === 'sqlite') {
                $table->rawColumn(
                    'amount_minor',
                    'integer not null check ('.self::ALLOCATION_AMOUNT_CHECK.')',
                );
            } else {
                $table->bigInteger('amount_minor');
            }

            $table->timestamps();

            $table->foreign(
                ['budget_id', 'user_id'],
                'budget_allocations_budget_user_foreign',
            )
                ->references(['id', 'user_id'])
                ->on('budgets')
                ->restrictOnDelete();

            $table->foreign(
                ['category_id', 'user_id', 'category_type'],
                'budget_allocations_category_user_type_foreign',
            )
                ->references(['id', 'user_id', 'type'])
                ->on('categories')
                ->restrictOnDelete();

            $table->unique(['budget_id', 'category_id'], 'budget_allocations_budget_id_category_id_unique');
            $table->index(['user_id', 'category_id'], 'budget_allocations_user_id_category_id_index');
            $table->index(['budget_id', 'user_id'], 'budget_allocations_budget_id_user_id_index');
        });

        if (in_array($driver, ['mariadb', 'mysql'], true)) {
            DB::statement(
                'ALTER TABLE budget_allocations ADD CONSTRAINT chk_budget_allocations_amount_and_type CHECK ('
                .self::ALLOCATION_AMOUNT_CHECK.')',
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

        Schema::dropIfExists('budget_allocations');
        Schema::dropIfExists('budgets');

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropUnique('categories_id_user_id_type_unique');
        });
    }

    private function assertSupportedDriver(string $driver): void
    {
        if (! in_array($driver, ['sqlite', 'mariadb', 'mysql'], true)) {
            throw new RuntimeException(
                sprintf(
                    'Unsupported database driver [%s] for budgets migration. Supported drivers: sqlite, mariadb, mysql.',
                    $driver,
                ),
            );
        }
    }
};
