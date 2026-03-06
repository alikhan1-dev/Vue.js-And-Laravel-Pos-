<?php

use App\Enums\SaleStatus;
use App\Enums\SaleType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Enforce type and status at DB level (MySQL). SQLite keeps string columns.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $types = implode("','", SaleType::values());
        $statuses = implode("','", SaleStatus::values());

        DB::statement("ALTER TABLE sales MODIFY COLUMN type ENUM('{$types}') NOT NULL");
        DB::statement("ALTER TABLE sales MODIFY COLUMN status ENUM('{$statuses}') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE sales MODIFY COLUMN type VARCHAR(20) NOT NULL');
        DB::statement('ALTER TABLE sales MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT \'pending\'');
    }
};
