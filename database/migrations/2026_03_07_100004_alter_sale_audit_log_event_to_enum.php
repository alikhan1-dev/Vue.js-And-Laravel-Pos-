<?php

use App\Models\SaleAuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Enforce event at DB level (MySQL). PHP constants remain source of truth.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $events = implode("','", [
            SaleAuditLog::EVENT_CREATED,
            SaleAuditLog::EVENT_CONVERTED_TO_SALE,
            SaleAuditLog::EVENT_RETURN_CREATED,
            SaleAuditLog::EVENT_STATUS_CHANGED,
        ]);

        DB::statement("ALTER TABLE sale_audit_log MODIFY COLUMN event ENUM('{$events}') NOT NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE sale_audit_log MODIFY COLUMN event VARCHAR(50) NOT NULL');
    }
};
