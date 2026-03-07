<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DB-level enforcement: sale_lines.warehouse_id must belong to the same
 * branch as the parent sale (warehouse.branch_id == sale.branch_id).
 *
 * This prevents data corruption from direct DB inserts, API bypasses, or
 * any code path that skips SaleService validation.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('
            CREATE TRIGGER sale_lines_warehouse_branch_check
            BEFORE INSERT ON sale_lines
            FOR EACH ROW
            BEGIN
                DECLARE v_sale_branch_id BIGINT;
                DECLARE v_wh_branch_id   BIGINT;

                SELECT branch_id INTO v_sale_branch_id
                  FROM sales WHERE id = NEW.sale_id LIMIT 1;

                SELECT branch_id INTO v_wh_branch_id
                  FROM warehouses WHERE id = NEW.warehouse_id LIMIT 1;

                IF v_sale_branch_id IS NOT NULL
                   AND v_wh_branch_id IS NOT NULL
                   AND v_sale_branch_id <> v_wh_branch_id THEN
                    SIGNAL SQLSTATE \'45000\'
                    SET MESSAGE_TEXT = \'sale_lines.warehouse_id must belong to the same branch as the sale (warehouse.branch_id != sale.branch_id)\';
                END IF;
            END
        ');

        DB::unprepared('
            CREATE TRIGGER sale_lines_warehouse_branch_check_update
            BEFORE UPDATE ON sale_lines
            FOR EACH ROW
            BEGIN
                DECLARE v_sale_branch_id BIGINT;
                DECLARE v_wh_branch_id   BIGINT;

                SELECT branch_id INTO v_sale_branch_id
                  FROM sales WHERE id = NEW.sale_id LIMIT 1;

                SELECT branch_id INTO v_wh_branch_id
                  FROM warehouses WHERE id = NEW.warehouse_id LIMIT 1;

                IF v_sale_branch_id IS NOT NULL
                   AND v_wh_branch_id IS NOT NULL
                   AND v_sale_branch_id <> v_wh_branch_id THEN
                    SIGNAL SQLSTATE \'45000\'
                    SET MESSAGE_TEXT = \'sale_lines.warehouse_id must belong to the same branch as the sale (warehouse.branch_id != sale.branch_id)\';
                END IF;
            END
        ');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS sale_lines_warehouse_branch_check');
        DB::unprepared('DROP TRIGGER IF EXISTS sale_lines_warehouse_branch_check_update');
    }
};
