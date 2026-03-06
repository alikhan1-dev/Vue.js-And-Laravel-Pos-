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
            if (! Schema::hasColumn('stock_cache', 'reorder_quantity')) {
                $table->decimal('reorder_quantity', 15, 2)->default(0)->after('reorder_level');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_cache')) {
            return;
        }

        Schema::table('stock_cache', function (Blueprint $table): void {
            if (Schema::hasColumn('stock_cache', 'reorder_quantity')) {
                $table->dropColumn('reorder_quantity');
            }
        });
    }
};

