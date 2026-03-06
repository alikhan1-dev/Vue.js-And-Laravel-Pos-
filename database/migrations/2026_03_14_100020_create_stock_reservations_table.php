<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->decimal('quantity', 15, 2); // reserved quantity (positive)
            $table->string('reference_type', 50); // e.g. Quotation, Cart, Order
            $table->unsignedBigInteger('reference_id'); // id of quotation/order/etc.
            $table->enum('status', ['active', 'released', 'cancelled'])->default('active');
            $table->timestamps();

            $table->index(['company_id', 'product_id', 'warehouse_id']);
            $table->index(['reference_type', 'reference_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};

