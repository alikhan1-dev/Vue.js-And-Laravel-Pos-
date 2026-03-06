<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add movement_date (ledger date; can differ from created_at for backdated imports).
     * Optionally increase quantity precision to decimal(18,4) for kg/liter/meter.
     */
    public function up(): void
    {
        if (! Schema::hasTable('stock_movements')) {
            return;
        }

        Schema::table('stock_movements', function (Blueprint $table): void {
            if (! Schema::hasColumn('stock_movements', 'movement_date')) {
                $table->dateTime('movement_date')->nullable()->after('reference_id');
            }
        });

        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE stock_movements MODIFY COLUMN quantity DECIMAL(18, 4) NOT NULL');
        }
        if ($driver === 'sqlite') {
            // SQLite does not support MODIFY; would require table rebuild. Skip quantity change for SQLite.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_movements')) {
            return;
        }

        Schema::table('stock_movements', function (Blueprint $table): void {
            if (Schema::hasColumn('stock_movements', 'movement_date')) {
                $table->dropColumn('movement_date');
            }
        });

        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE stock_movements MODIFY COLUMN quantity DECIMAL(15, 2) NOT NULL');
        }
    }
};
