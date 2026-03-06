<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if ($driver !== 'mysql') {
            return;
        }
        $statuses = implode("','", \App\Enums\PaymentStatus::values());
        DB::statement("ALTER TABLE payments MODIFY COLUMN status ENUM('{$statuses}') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver !== 'mysql') {
            return;
        }
        DB::statement('ALTER TABLE payments MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT \'pending\'');
    }
};
