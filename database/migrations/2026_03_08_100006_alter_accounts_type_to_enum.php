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
        $types = implode("','", \App\Enums\AccountType::values());
        DB::statement("ALTER TABLE accounts MODIFY COLUMN type ENUM('{$types}') NOT NULL");
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver !== 'mysql') {
            return;
        }
        DB::statement('ALTER TABLE accounts MODIFY COLUMN type VARCHAR(30) NOT NULL');
    }
};
