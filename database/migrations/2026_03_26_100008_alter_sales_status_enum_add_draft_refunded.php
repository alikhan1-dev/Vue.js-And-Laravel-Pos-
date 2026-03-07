<?php

use App\Enums\SaleStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $statuses = implode("','", SaleStatus::values());
        DB::statement("ALTER TABLE sales MODIFY COLUMN status ENUM('{$statuses}') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE sales MODIFY COLUMN status ENUM('draft','pending','completed','cancelled','refunded') NOT NULL DEFAULT 'pending'");
    }
};
