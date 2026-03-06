<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Financial double-entry layer for inventory: links movements to accounting (Debit/Credit).
     * Enables integration with GL: Inventory, COGS, Accounts Payable, etc.
     */
    public function up(): void
    {
        Schema::create('inventory_journal', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('stock_movement_id')->nullable();
            $table->date('journal_date');
            $table->string('entry_type', 50); // e.g. purchase_in, sale_out (mirrors movement type)
            $table->string('account_type', 50); // inventory, cogs, accounts_payable, etc.
            $table->decimal('debit_amount', 18, 4)->default(0);
            $table->decimal('credit_amount', 18, 4)->default(0);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'journal_date']);
            $table->index('stock_movement_id');
            $table->index(['entry_type', 'journal_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_journal');
    }
};
