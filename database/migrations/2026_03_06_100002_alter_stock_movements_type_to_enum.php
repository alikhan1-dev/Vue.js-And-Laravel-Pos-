<?php

use App\Enums\StockMovementType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Ensure stock_movements.type is DB-level ENUM (for existing installs that used string).
     */
    public function up(): void
    {
        $driver = DB::getDriverName();
        $values = implode("','", StockMovementType::values());

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE stock_movements MODIFY COLUMN type ENUM('{$values}') NOT NULL");
        }
        // SQLite does not support ALTER COLUMN type; leave as-is for SQLite
    }

    /**
     * Reverse: revert to string (optional; only if downgrading).
     */
    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE stock_movements MODIFY COLUMN type VARCHAR(30) NOT NULL');
        }
    }
};
