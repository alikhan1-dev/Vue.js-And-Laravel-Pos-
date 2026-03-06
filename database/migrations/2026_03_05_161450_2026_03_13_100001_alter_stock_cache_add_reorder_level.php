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
        if (! Schema::hasTable('stock_cache')) {
            return;
        }

        Schema::table('stock_cache', function (Blueprint $table): void {
            if (! Schema::hasColumn('stock_cache', 'reorder_level')) {
                $table->decimal('reorder_level', 15, 2)->default(0)->after('reserved_quantity');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('stock_cache')) {
            return;
        }

        Schema::table('stock_cache', function (Blueprint $table): void {
            if (Schema::hasColumn('stock_cache', 'reorder_level')) {
                $table->dropColumn('reorder_level');
            }
        });
    }
};
