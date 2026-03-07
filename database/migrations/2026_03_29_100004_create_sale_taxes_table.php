<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-rate tax breakdown for VAT/GST reporting and future Tax Engine.
     */
    public function up(): void
    {
        Schema::create('sale_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->unsignedBigInteger('tax_rate_id')->nullable(); // FK when tax_rates table exists
            $table->string('tax_name', 100)->nullable();
            $table->decimal('tax_rate_percent', 8, 4)->nullable();
            $table->decimal('taxable_amount', 15, 2);
            $table->decimal('tax_amount', 15, 2);
            $table->timestamps();

            $table->index('sale_id');
            $table->index('tax_rate_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_taxes');
    }
};
