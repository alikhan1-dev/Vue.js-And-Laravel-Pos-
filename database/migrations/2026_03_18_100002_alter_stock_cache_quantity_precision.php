<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_cache')) {
            return;
        }
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE stock_cache MODIFY COLUMN quantity DECIMAL(18, 4) NOT NULL DEFAULT 0');
            DB::statement('ALTER TABLE stock_cache MODIFY COLUMN reserved_quantity DECIMAL(18, 4) NOT NULL DEFAULT 0');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_cache')) {
            return;
        }
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE stock_cache MODIFY COLUMN quantity DECIMAL(15, 2) NOT NULL DEFAULT 0');
            DB::statement('ALTER TABLE stock_cache MODIFY COLUMN reserved_quantity DECIMAL(15, 2) NOT NULL DEFAULT 0');
        }
    }
};
