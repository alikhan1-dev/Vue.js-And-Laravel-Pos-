<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('product_serials')) {
            return;
        }

        Schema::table('product_serials', function (Blueprint $table): void {
            if (! Schema::hasColumn('product_serials', 'sale_id')) {
                $table->foreignId('sale_id')->nullable()->after('warehouse_id')->constrained('sales')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('product_serials')) {
            return;
        }

        Schema::table('product_serials', function (Blueprint $table): void {
            if (Schema::hasColumn('product_serials', 'sale_id')) {
                $table->dropForeign(['sale_id']);
                $table->dropColumn('sale_id');
            }
        });
    }
};
