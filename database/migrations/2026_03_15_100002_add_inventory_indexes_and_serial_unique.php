<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // stock_movements: composite index for company + product queries
        if (! Schema::hasIndex('stock_movements', ['company_id', 'product_id'])) {
            Schema::table('stock_movements', function (Blueprint $table): void {
                $table->index(['company_id', 'product_id']);
            });
        }

        // stock_movements: prevent same serial being used twice in the same sale/reference
        if (! Schema::hasIndex('stock_movements', ['serial_id', 'reference_type', 'reference_id'], 'unique')) {
            Schema::table('stock_movements', function (Blueprint $table): void {
                $table->unique(['serial_id', 'reference_type', 'reference_id'], 'stock_movements_serial_id_reference_type_reference_id_unique');
            });
        }

        // stock_cache: composite for company + warehouse + product lookups
        if (Schema::hasTable('stock_cache') && ! Schema::hasIndex('stock_cache', ['company_id', 'warehouse_id', 'product_id'])) {
            Schema::table('stock_cache', function (Blueprint $table): void {
                $table->index(['company_id', 'warehouse_id', 'product_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'product_id']);
            $table->dropUnique('stock_movements_serial_id_reference_type_reference_id_unique');
        });

        if (Schema::hasTable('stock_cache')) {
            Schema::table('stock_cache', function (Blueprint $table): void {
                $table->dropIndex(['company_id', 'warehouse_id', 'product_id']);
            });
        }
    }
};
