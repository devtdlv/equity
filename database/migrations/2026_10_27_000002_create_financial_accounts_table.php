<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('financial_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('type', 32);
            $table->char('currency_code', 3);
            $table->string('institution', 120)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->foreign('currency_code')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();

            // Supports composite foreign-key verification from ledger_accounts(financial_account_id, user_id)
            $table->unique(['id', 'user_id']);
            $table->index(['user_id', 'is_archived']);
            $table->index(['user_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('financial_accounts');
    }
};
