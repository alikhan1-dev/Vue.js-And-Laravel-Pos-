<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sale adjustments for completed/immutable sales. Instead of editing a
 * completed sale (which is forbidden), admins create a reversal adjustment
 * that offsets the original entry with new accounting postings.
 *
 * This is the ERP-standard approach (SAP credit/debit memo, Odoo refund
 * invoice) that avoids legal/accounting issues with editing posted entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->foreignId('sale_id')->constrained('sales');
            $table->string('adjustment_number', 50)->nullable();
            $table->string('type', 30);
            $table->decimal('amount', 15, 2);
            $table->string('reason', 500);
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->string('status', 20)->default('pending');
            $table->json('metadata')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'adjustment_number']);
            $table->index(['company_id', 'sale_id']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_adjustments');
    }
};
