<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_cache')) {
            return;
        }

        Schema::table('stock_cache', function (Blueprint $table): void {
            if (! Schema::hasColumn('stock_cache', 'company_id')) {
                $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->cascadeOnDelete();
                $table->index('company_id');
            }

            if (! Schema::hasColumn('stock_cache', 'variant_id')) {
                $table->foreignId('variant_id')->nullable()->after('warehouse_id')->constrained('product_variants')->nullOnDelete();
                $table->index('variant_id');
            }

            if (! Schema::hasColumn('stock_cache', 'reserved_quantity')) {
                $table->decimal('reserved_quantity', 15, 2)->default(0)->after('quantity');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_cache')) {
            return;
        }

        Schema::table('stock_cache', function (Blueprint $table): void {
            if (Schema::hasColumn('stock_cache', 'company_id')) {
                $table->dropForeign(['company_id']);
                $table->dropIndex(['company_id']);
                $table->dropColumn('company_id');
            }
            if (Schema::hasColumn('stock_cache', 'variant_id')) {
                $table->dropForeign(['variant_id']);
                $table->dropIndex(['variant_id']);
                $table->dropColumn('variant_id');
            }
            if (Schema::hasColumn('stock_cache', 'reserved_quantity')) {
                $table->dropColumn('reserved_quantity');
            }
        });
    }
};
