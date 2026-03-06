<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $sm = Schema::getConnection()->getDriverName();
            if ($sm === 'mysql') {
                $result = Schema::getConnection()->select(
                    "SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'products' AND index_name = 'products_barcode_index' LIMIT 1"
                );
                if (count($result) === 0) {
                    $table->index('barcode', 'products_barcode_index');
                }
                $result2 = Schema::getConnection()->select(
                    "SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'products' AND index_name = 'products_sku_index' LIMIT 1"
                );
                if (count($result2) === 0) {
                    $table->index('sku', 'products_sku_index');
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_barcode_index');
            $table->dropIndex('products_sku_index');
        });
    }
};
