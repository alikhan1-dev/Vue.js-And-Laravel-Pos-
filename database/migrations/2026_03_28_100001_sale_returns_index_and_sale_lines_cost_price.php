<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 1) sale_returns: add (company_id, sale_id) for "returns for sale" with tenant filtering.
     * 2) sale_lines: add cost_price_at_sale for profit reporting and margin analytics.
     */
    public function up(): void
    {
        if (Schema::hasTable('sale_returns')) {
            Schema::table('sale_returns', function (Blueprint $table): void {
                $table->index(['company_id', 'sale_id'], 'sale_returns_company_id_sale_id_index');
            });
        }

        if (Schema::hasTable('sale_lines') && ! Schema::hasColumn('sale_lines', 'cost_price_at_sale')) {
            Schema::table('sale_lines', function (Blueprint $table): void {
                $table->decimal('cost_price_at_sale', 15, 4)->nullable()->after('unit_price');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sale_returns')) {
            Schema::table('sale_returns', fn (Blueprint $table) => $table->dropIndex('sale_returns_company_id_sale_id_index'));
        }
        if (Schema::hasTable('sale_lines') && Schema::hasColumn('sale_lines', 'cost_price_at_sale')) {
            Schema::table('sale_lines', fn (Blueprint $table) => $table->dropColumn('cost_price_at_sale'));
        }
    }
};
