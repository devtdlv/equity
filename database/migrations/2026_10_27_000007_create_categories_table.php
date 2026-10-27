<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHECK_EXPRESSION = "type IN ('income', 'expense') "
        .'AND ((is_archived = 0 AND archived_at IS NULL) OR (is_archived = 1 AND archived_at IS NOT NULL))';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::create('categories', function (Blueprint $table) use ($driver): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('name', 80);
            $table->string('type', 16);
            $table->boolean('is_archived')->default(false);

            if ($driver === 'sqlite') {
                $table->rawColumn(
                    'archived_at',
                    'datetime check ('.self::CHECK_EXPRESSION.')',
                )->nullable();
            } else {
                $table->timestamp('archived_at')->nullable();
            }

            $table->timestamps();

            $table->unique(['id', 'user_id'], 'categories_id_user_id_unique');
            $table->unique(['user_id', 'type', 'name'], 'categories_user_id_type_name_unique');
            $table->index(['user_id', 'type', 'is_archived'], 'categories_user_id_type_is_archived_index');
        });

        if (in_array($driver, ['mariadb', 'mysql', 'pgsql'], true)) {
            DB::statement(
                'ALTER TABLE categories ADD CONSTRAINT chk_categories_type_and_archive CHECK ('.self::CHECK_EXPRESSION.')',
            );
        }

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER trg_categories_validate_update
                BEFORE UPDATE ON categories
                BEGIN
                    SELECT CASE
                        WHEN (OLD.user_id IS NOT NEW.user_id)
                        THEN RAISE(ABORT, 'Category ownership cannot be modified.')
                        WHEN (OLD.type IS NOT NEW.type)
                        THEN RAISE(ABORT, 'Category type cannot be changed after creation.')
                    END;
                END;
            SQL);
        } elseif (in_array($driver, ['mariadb', 'mysql'], true)) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER trg_categories_validate_update
                BEFORE UPDATE ON categories
                FOR EACH ROW
                BEGIN
                    IF NOT (OLD.user_id <=> NEW.user_id) THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Category ownership cannot be modified.';
                    END IF;

                    IF NOT (OLD.type <=> NEW.type) THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Category type cannot be changed after creation.';
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
            DB::unprepared('DROP TRIGGER IF EXISTS trg_categories_validate_update');
        }

        Schema::dropIfExists('categories');
    }
};
