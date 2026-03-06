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
        if (! Schema::hasTable('product_batches')) {
            return;
        }

        Schema::table('product_batches', function (Blueprint $table): void {
            if (! Schema::hasColumn('product_batches', 'manufacture_date')) {
                $table->date('manufacture_date')->nullable()->after('warehouse_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('product_batches')) {
            return;
        }

        Schema::table('product_batches', function (Blueprint $table): void {
            if (Schema::hasColumn('product_batches', 'manufacture_date')) {
                $table->dropColumn('manufacture_date');
            }
        });
    }
};
