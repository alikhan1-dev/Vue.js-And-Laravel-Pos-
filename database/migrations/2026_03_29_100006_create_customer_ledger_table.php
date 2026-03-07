<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customer ledger for aging, credit control, statements. Tracks invoice, payment, refund, adjustment, credit.
     */
    public function up(): void
    {
        Schema::create('customer_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('type', 20); // invoice, payment, refund, adjustment, credit
            $table->string('reference_type', 50)->nullable(); // Sale, Payment, SaleReturn, etc.
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->decimal('amount', 15, 2); // signed: + for debit (invoice), - for credit (payment/refund)
            $table->decimal('balance_after', 15, 2)->nullable(); // running balance after this entry
            $table->date('entry_date');
            $table->string('description', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'customer_id']);
            $table->index(['customer_id', 'entry_date']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_ledger');
    }
};
