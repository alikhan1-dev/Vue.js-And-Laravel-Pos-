<?php

use App\Models\SaleAuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add adjustment_created and adjustment_approved to sale_audit_log.event ENUM.
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
            SaleAuditLog::EVENT_ADJUSTMENT_CREATED,
            SaleAuditLog::EVENT_ADJUSTMENT_APPROVED,
        ]);

        DB::statement("ALTER TABLE sale_audit_log MODIFY COLUMN event ENUM('{$events}') NOT NULL");
    }

    public function down(): void
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
};
