<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->after('product_id')->constrained('product_variants')->nullOnDelete();
            $table->decimal('unit_cost', 15, 4)->nullable()->after('quantity');
            $table->foreignId('batch_id')->nullable()->after('unit_cost')->constrained('product_batches')->nullOnDelete();
            $table->foreignId('serial_id')->nullable()->after('batch_id')->constrained('product_serials')->nullOnDelete();

            $table->index('company_id');
            $table->index('variant_id');
            $table->index('batch_id');
            $table->index('serial_id');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropForeign(['variant_id']);
            $table->dropForeign(['batch_id']);
            $table->dropForeign(['serial_id']);
            $table->dropIndex(['company_id']);
            $table->dropIndex(['variant_id']);
            $table->dropIndex(['batch_id']);
            $table->dropIndex(['serial_id']);
            $table->dropColumn(['company_id', 'variant_id', 'unit_cost', 'batch_id', 'serial_id']);
        });
    }
};
