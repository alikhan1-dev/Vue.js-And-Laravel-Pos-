<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 1) sales: device_id (POS terminal), pos_session_id (cash management).
     * 2) sale_lines: product_name_snapshot, sku_snapshot, barcode_snapshot, tax_class_id_snapshot.
     */
    public function up(): void
    {
        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table): void {
                if (! Schema::hasColumn('sales', 'device_id')) {
                    $table->string('device_id', 100)->nullable()->after('updated_by');
                }
                if (! Schema::hasColumn('sales', 'pos_session_id')) {
                    $table->foreignId('pos_session_id')->nullable()->after('device_id')->constrained('pos_sessions')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('sale_lines')) {
            Schema::table('sale_lines', function (Blueprint $table): void {
                if (! Schema::hasColumn('sale_lines', 'product_name_snapshot')) {
                    $table->string('product_name_snapshot', 255)->nullable()->after('product_id');
                }
                if (! Schema::hasColumn('sale_lines', 'sku_snapshot')) {
                    $table->string('sku_snapshot', 100)->nullable()->after('product_name_snapshot');
                }
                if (! Schema::hasColumn('sale_lines', 'barcode_snapshot')) {
                    $table->string('barcode_snapshot', 100)->nullable()->after('sku_snapshot');
                }
                if (! Schema::hasColumn('sale_lines', 'tax_class_id_snapshot')) {
                    $table->unsignedBigInteger('tax_class_id_snapshot')->nullable()->after('barcode_snapshot');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table): void {
                if (Schema::hasColumn('sales', 'pos_session_id')) {
                    $table->dropForeign(['pos_session_id']);
                }
                if (Schema::hasColumn('sales', 'device_id')) {
                    $table->dropColumn('device_id');
                }
            });
        }
        if (Schema::hasTable('sale_lines')) {
            Schema::table('sale_lines', function (Blueprint $table): void {
                foreach (['product_name_snapshot', 'sku_snapshot', 'barcode_snapshot', 'tax_class_id_snapshot'] as $col) {
                    if (Schema::hasColumn('sale_lines', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
