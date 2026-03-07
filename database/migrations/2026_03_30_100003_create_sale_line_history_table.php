<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for sale line edits before completion. Tracks quantity, price,
 * and discount changes on draft sales for compliance, analytics, and error recovery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_line_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales');
            $table->foreignId('sale_line_id')->nullable()->constrained('sale_lines');
            $table->foreignId('company_id')->constrained('companies');
            $table->string('action', 20);
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('variant_id')->nullable()->constrained('product_variants');
            $table->decimal('old_quantity', 15, 2)->nullable();
            $table->decimal('new_quantity', 15, 2)->nullable();
            $table->decimal('old_unit_price', 15, 2)->nullable();
            $table->decimal('new_unit_price', 15, 2)->nullable();
            $table->decimal('old_discount', 15, 2)->nullable();
            $table->decimal('new_discount', 15, 2)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users');
            $table->timestamp('changed_at')->useCurrent();
            $table->timestamps();

            $table->index(['sale_id', 'changed_at']);
            $table->index(['company_id', 'sale_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_line_history');
    }
};
