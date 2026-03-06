<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->date('manufacture_date')->nullable();
            $table->string('batch_number', 100);
            $table->date('expiry_date')->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'warehouse_id', 'batch_number']);
            $table->index(['product_id', 'warehouse_id']);
            $table->index('expiry_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_batches');
    }
};
