<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->string('alert_type', 50); // low_stock, expiry_near, serial_conflict, negative_stock_attempt
            $table->string('severity', 20)->default('warning'); // info, warning, critical
            $table->text('message')->nullable();
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->boolean('is_resolved')->default(false);
            $table->dateTime('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'alert_type', 'is_resolved'], 'inv_alerts_company_type_resolved');
            $table->index(['product_id', 'warehouse_id'], 'inv_alerts_product_warehouse');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_alerts');
    }
};
