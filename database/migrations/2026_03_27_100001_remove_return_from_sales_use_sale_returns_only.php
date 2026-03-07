<?php

use App\Enums\SaleType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Single return system: use sale_returns only. Remove type=return and return_for_sale_id from sales.
     */
    public function up(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        // Drop return_for_sale_id index then column (MySQL requires index dropped first)
        if (Schema::hasColumn('sales', 'return_for_sale_id')) {
            if (DB::getDriverName() === 'mysql') {
                try {
                    DB::statement('ALTER TABLE sales DROP INDEX sales_return_for_sale_id_index');
                } catch (\Throwable) {
                    // Index may not exist on some environments
                }
            }
            Schema::table('sales', function (Blueprint $table): void {
                $table->dropColumn('return_for_sale_id');
            });
        }

        // Restrict type enum to sale, quotation only (remove return). Migrate existing type=return to quotation.
        DB::table('sales')->where('type', 'return')->update(['type' => 'quotation']);
        $types = implode("','", SaleType::values());
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE sales MODIFY COLUMN type ENUM('{$types}') NOT NULL");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        Schema::table('sales', function (Blueprint $table): void {
            if (! Schema::hasColumn('sales', 'return_for_sale_id')) {
                $table->unsignedBigInteger('return_for_sale_id')->nullable()->after('created_by');
                $table->index('return_for_sale_id');
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE sales MODIFY COLUMN type ENUM('sale','quotation','return') NOT NULL");
        }
    }
};
