<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nullable opening-journal pointer on financial_accounts.
 *
 * UNIQUE (opening_journal_entry_id) prevents two accounts from sharing one journal.
 * It does not independently prevent multiple opening journals for one account:
 * extra unreferenced journal_entries can still exist, and this column is not
 * protected by journal immutability triggers.
 *
 * The composite foreign key requires journal_entries(id, user_id, currency_code)
 * (unique index from migration 000009). MATCH SIMPLE allows a NULL pointer
 * while user_id and currency_code remain populated (legacy accounts and zero
 * amounts). No journals are created or backfilled.
 */
return new class extends Migration
{
    public const UNIQUE_INDEX = 'financial_accounts_opening_journal_entry_id_unique';

    public const COMPOSITE_FOREIGN = 'financial_accounts_opening_journal_tenant_currency_foreign';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $this->assertSupportedDriver($driver);

        if (! Schema::hasTable('financial_accounts')) {
            throw new RuntimeException(
                'Cannot add opening_journal_entry_id because financial_accounts does not exist.',
            );
        }

        if (! Schema::hasTable('journal_entries')) {
            throw new RuntimeException(
                'Cannot add opening_journal_entry_id because journal_entries does not exist.',
            );
        }

        if (Schema::hasColumn('financial_accounts', 'opening_journal_entry_id')) {
            return;
        }

        Schema::table('financial_accounts', function (Blueprint $table): void {
            $table->unsignedBigInteger('opening_journal_entry_id')->nullable()->after('archived_at');

            $table->unique('opening_journal_entry_id', self::UNIQUE_INDEX);

            // Composite FK includes the journal id reference. A second FK on
            // opening_journal_entry_id alone would be redundant.
            $table->foreign(
                ['opening_journal_entry_id', 'user_id', 'currency_code'],
                self::COMPOSITE_FOREIGN,
            )
                ->references(['id', 'user_id', 'currency_code'])
                ->on('journal_entries')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $this->assertSupportedDriver($driver);

        if (! Schema::hasTable('financial_accounts')) {
            return;
        }

        if (! Schema::hasColumn('financial_accounts', 'opening_journal_entry_id')) {
            return;
        }

        Schema::table('financial_accounts', function (Blueprint $table): void {
            $table->dropForeign(self::COMPOSITE_FOREIGN);
            $table->dropUnique(self::UNIQUE_INDEX);
            $table->dropColumn('opening_journal_entry_id');
        });
    }

    private function assertSupportedDriver(string $driver): void
    {
        if (! in_array($driver, ['sqlite', 'mariadb', 'mysql'], true)) {
            throw new RuntimeException(sprintf(
                'Unsupported database driver [%s] for opening journal reference migration. Supported drivers: sqlite, mariadb, mysql.',
                $driver,
            ));
        }
    }
};
