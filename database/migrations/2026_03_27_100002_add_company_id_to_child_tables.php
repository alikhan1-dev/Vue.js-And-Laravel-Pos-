<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add company_id to child tables for row-level tenant filtering and indexing (professional ERP pattern).
     */
    public function up(): void
    {
        if (Schema::hasTable('sale_lines') && ! Schema::hasColumn('sale_lines', 'company_id')) {
            Schema::table('sale_lines', function (Blueprint $table): void {
                $table->foreignId('company_id')->nullable()->after('sale_id')->constrained('companies')->nullOnDelete();
            });
            $this->backfillSaleLinesCompanyId();
            Schema::table('sale_lines', function (Blueprint $table): void {
                $table->index('company_id');
            });
        }

        if (Schema::hasTable('sale_discounts') && ! Schema::hasColumn('sale_discounts', 'company_id')) {
            Schema::table('sale_discounts', function (Blueprint $table): void {
                $table->foreignId('company_id')->nullable()->after('sale_id')->constrained('companies')->nullOnDelete();
            });
            $this->backfillSaleDiscountsCompanyId();
            Schema::table('sale_discounts', fn (Blueprint $t) => $t->index('company_id'));
        }

        if (Schema::hasTable('customer_addresses') && ! Schema::hasColumn('customer_addresses', 'company_id')) {
            Schema::table('customer_addresses', function (Blueprint $table): void {
                $table->foreignId('company_id')->nullable()->after('customer_id')->constrained('companies')->nullOnDelete();
            });
            $this->backfillCustomerAddressesCompanyId();
            Schema::table('customer_addresses', fn (Blueprint $t) => $t->index('company_id'));
        }

        if (Schema::hasTable('sale_return_items') && ! Schema::hasColumn('sale_return_items', 'company_id')) {
            Schema::table('sale_return_items', function (Blueprint $table): void {
                $table->foreignId('company_id')->nullable()->after('sale_return_id')->constrained('companies')->nullOnDelete();
            });
            $this->backfillSaleReturnItemsCompanyId();
            Schema::table('sale_return_items', fn (Blueprint $t) => $t->index('company_id'));
        }
    }

    private function backfillSaleLinesCompanyId(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('UPDATE sale_lines sl INNER JOIN sales s ON sl.sale_id = s.id SET sl.company_id = s.company_id');
        } else {
            foreach (DB::table('sale_lines')->join('sales', 'sale_lines.sale_id', '=', 'sales.id')->select('sale_lines.id', 'sales.company_id')->get() as $row) {
                DB::table('sale_lines')->where('id', $row->id)->update(['company_id' => $row->company_id]);
            }
        }
    }

    private function backfillSaleDiscountsCompanyId(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('UPDATE sale_discounts sd INNER JOIN sales s ON sd.sale_id = s.id SET sd.company_id = s.company_id');
        } else {
            foreach (DB::table('sale_discounts')->join('sales', 'sale_discounts.sale_id', '=', 'sales.id')->select('sale_discounts.id', 'sales.company_id')->get() as $row) {
                DB::table('sale_discounts')->where('id', $row->id)->update(['company_id' => $row->company_id]);
            }
        }
    }

    private function backfillCustomerAddressesCompanyId(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('UPDATE customer_addresses ca INNER JOIN customers c ON ca.customer_id = c.id SET ca.company_id = c.company_id');
        } else {
            foreach (DB::table('customer_addresses')->join('customers', 'customer_addresses.customer_id', '=', 'customers.id')->select('customer_addresses.id', 'customers.company_id')->get() as $row) {
                DB::table('customer_addresses')->where('id', $row->id)->update(['company_id' => $row->company_id]);
            }
        }
    }

    private function backfillSaleReturnItemsCompanyId(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('UPDATE sale_return_items sri INNER JOIN sale_returns sr ON sri.sale_return_id = sr.id SET sri.company_id = sr.company_id');
        } else {
            foreach (DB::table('sale_return_items')->join('sale_returns', 'sale_return_items.sale_return_id', '=', 'sale_returns.id')->select('sale_return_items.id', 'sale_returns.company_id')->get() as $row) {
                DB::table('sale_return_items')->where('id', $row->id)->update(['company_id' => $row->company_id]);
            }
        }
    }

    public function down(): void
    {
        foreach (['sale_lines', 'sale_discounts', 'customer_addresses', 'sale_return_items'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'company_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $table): void {
                $table->dropForeign(['company_id']);
                $table->dropColumn('company_id');
            });
        }
    }
};
