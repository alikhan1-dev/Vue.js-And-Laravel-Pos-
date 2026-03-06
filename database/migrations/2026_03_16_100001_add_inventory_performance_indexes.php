<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add performance indexes for inventory queries if not already present.
     * Backward-compatible: only adds indexes that are missing.
     */
    public function up(): void
    {
        if (! Schema::hasTable('stock_movements')) {
            return;
        }

        // stock_movements: company + product (tenant + product lookups)
        if (! Schema::hasIndex('stock_movements', ['company_id', 'product_id'])) {
            Schema::table('stock_movements', function (Blueprint $table): void {
                $table->index(['company_id', 'product_id']);
            });
        }

        // stock_movements: product + warehouse (POS/stock by warehouse)
        if (! Schema::hasIndex('stock_movements', ['product_id', 'warehouse_id'])) {
            Schema::table('stock_movements', function (Blueprint $table): void {
                $table->index(['product_id', 'warehouse_id']);
            });
        }

        // stock_movements: reference lookups (Sale, Transfer, etc.)
        if (! Schema::hasIndex('stock_movements', ['reference_type', 'reference_id'])) {
            Schema::table('stock_movements', function (Blueprint $table): void {
                $table->index(['reference_type', 'reference_id']);
            });
        }

        // stock_cache: company + warehouse + product (tenant stock report)
        if (Schema::hasTable('stock_cache') && ! Schema::hasIndex('stock_cache', ['company_id', 'warehouse_id', 'product_id'])) {
            Schema::table('stock_cache', function (Blueprint $table): void {
                $table->index(['company_id', 'warehouse_id', 'product_id']);
            });
        }

        // product_serials: unique serial_number (add only if missing)
        if (Schema::hasTable('product_serials') && ! Schema::hasIndex('product_serials', ['serial_number'], 'unique')) {
            Schema::table('product_serials', function (Blueprint $table): void {
                $table->unique('serial_number');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('stock_movements')) {
            Schema::table('stock_movements', function (Blueprint $table): void {
                $table->dropIndex(['company_id', 'product_id']);
                $table->dropIndex(['product_id', 'warehouse_id']);
                $table->dropIndex(['reference_type', 'reference_id']);
            });
        }
        if (Schema::hasTable('stock_cache')) {
            Schema::table('stock_cache', function (Blueprint $table): void {
                $table->dropIndex(['company_id', 'warehouse_id', 'product_id']);
            });
        }
        if (Schema::hasTable('product_serials')) {
            Schema::table('product_serials', function (Blueprint $table): void {
                $table->dropUnique(['serial_number']);
            });
        }
    }
};
