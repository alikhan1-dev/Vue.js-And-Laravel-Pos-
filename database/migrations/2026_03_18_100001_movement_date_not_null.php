<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ledger systems must always have a transaction date.
     * movement_date NOT NULL, default CURRENT_TIMESTAMP.
     */
    public function up(): void
    {
        if (! Schema::hasTable('stock_movements') || ! Schema::hasColumn('stock_movements', 'movement_date')) {
            return;
        }

        $driver = DB::getDriverName();
        DB::statement('UPDATE stock_movements SET movement_date = COALESCE(movement_date, created_at) WHERE movement_date IS NULL');
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE stock_movements MODIFY COLUMN movement_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
        }
        // SQLite: column remains nullable; application layer enforces movement_date in model
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_movements')) {
            return;
        }
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE stock_movements MODIFY COLUMN movement_date DATETIME NULL');
        }
    }
};
