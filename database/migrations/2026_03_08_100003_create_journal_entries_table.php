<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('reference_type', 50); // Sale, Payment, Adjustment, Refund
            $table->unsignedBigInteger('reference_id');
            $table->string('entry_type', 50); // sale_posting, payment_receipt, refund, adjustment
            $table->string('status', 20)->default('posted'); // draft, posted, reversed
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->boolean('is_locked')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'reference_type', 'reference_id']);
            $table->index(['company_id', 'entry_type']);
            $table->index('created_at');
            $table->index('posted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
