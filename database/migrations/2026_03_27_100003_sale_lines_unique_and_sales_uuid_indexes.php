<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * 1) Unique (sale_id, product_id, variant_id) on sale_lines to prevent duplicate lines; merge in service.
     * 2) sales.uuid for offline POS sync.
     * 3) Indexes (company_id, created_at), (company_id, payment_status) for dashboards.
     */
    public function up(): void
    {
        if (Schema::hasTable('sales') && ! Schema::hasColumn('sales', 'uuid')) {
            Schema::table('sales', function (Blueprint $table): void {
                $table->uuid('uuid')->nullable()->after('id');
            });
            $this->backfillSalesUuid();
            Schema::table('sales', function (Blueprint $table): void {
                $table->unique('uuid');
            });
        }

        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table): void {
                $table->index(['company_id', 'created_at'], 'sales_company_id_created_at_index');
                $table->index(['company_id', 'payment_status'], 'sales_company_id_payment_status_index');
            });
        }

        // sale_lines: duplicate prevention is enforced in SaleService by merging quantities for same (product_id, variant_id).
        // No unique DB constraint here because variant_id can be NULL (multiple NULLs would violate unique in some DBs).
    }

    private function backfillSalesUuid(): void
    {
        foreach (\Illuminate\Support\Facades\DB::table('sales')->whereNull('uuid')->get() as $sale) {
            \Illuminate\Support\Facades\DB::table('sales')->where('id', $sale->id)->update(['uuid' => (string) Str::uuid()]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table): void {
                $table->dropIndex('sales_company_id_created_at_index');
                $table->dropIndex('sales_company_id_payment_status_index');
            });
            if (Schema::hasColumn('sales', 'uuid')) {
                Schema::table('sales', fn (Blueprint $table) => $table->dropUnique(['uuid']));
                Schema::table('sales', fn (Blueprint $table) => $table->dropColumn('uuid'));
            }
        }
    }
};
