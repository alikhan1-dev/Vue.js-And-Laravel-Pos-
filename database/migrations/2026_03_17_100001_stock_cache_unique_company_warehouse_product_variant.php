<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add critical unique index to prevent duplicate stock_cache rows.
     * UNIQUE(company_id, warehouse_id, product_id, variant_id).
     */
    public function up(): void
    {
        if (! Schema::hasTable('stock_cache')) {
            return;
        }

        Schema::table('stock_cache', function (Blueprint $table): void {
            if (! Schema::hasIndex('stock_cache', ['company_id', 'warehouse_id', 'product_id', 'variant_id'], 'unique')) {
                $table->unique(
                    ['company_id', 'warehouse_id', 'product_id', 'variant_id'],
                    'stock_cache_company_warehouse_product_variant_unique'
                );
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_cache')) {
            return;
        }

        Schema::table('stock_cache', function (Blueprint $table): void {
            $table->dropUnique('stock_cache_company_warehouse_product_variant_unique');
        });
    }
};
