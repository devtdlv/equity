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
        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('financial_account_id')->nullable()->unique();
            $table->string('code', 64);
            $table->string('name', 120);
            $table->string('classification', 32);
            $table->char('currency_code', 3);
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->foreign(['financial_account_id', 'user_id'])
                ->references(['id', 'user_id'])
                ->on('financial_accounts')
                ->restrictOnDelete();

            $table->foreign('currency_code')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();

            $table->unique(['user_id', 'code']);
            $table->index(['user_id', 'classification', 'currency_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_accounts');
    }
};
