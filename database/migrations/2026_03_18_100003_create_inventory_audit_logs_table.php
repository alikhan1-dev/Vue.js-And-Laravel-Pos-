<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stock audit trail for debugging inventory discrepancies.
     */
    public function up(): void
    {
        Schema::create('inventory_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->decimal('old_quantity', 18, 4)->nullable();
            $table->decimal('new_quantity', 18, 4)->nullable();
            $table->string('action', 50); // movement_created, cache_updated, adjustment, etc.
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
            $table->index(['product_id', 'warehouse_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_audit_logs');
    }
};
