<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('warehouses')) {
            return;
        }

        Schema::table('warehouses', function (Blueprint $table): void {
            // Slug for APIs / URLs / routing (optional).
            if (! Schema::hasColumn('warehouses', 'slug')) {
                $table->string('slug', 120)->nullable()->after('name');
            }

            // Index to speed up "find warehouse by code" in a tenant.
            if (! Schema::hasIndex('warehouses', ['company_id', 'code'])) {
                $table->index(['company_id', 'code'], 'warehouses_company_id_code_index');
            }
        });

        // Enforce at most one default warehouse per branch at the DB level (MySQL only).
        if (DB::getDriverName() === 'mysql') {
            if (Schema::hasColumn('warehouses', 'is_default')) {
                // Add a generated column which is NULL when not default, and 1 when default.
                if (! Schema::hasColumn('warehouses', 'is_default_enforced')) {
                    DB::statement("
                        ALTER TABLE warehouses
                        ADD COLUMN is_default_enforced TINYINT(1)
                        GENERATED ALWAYS AS (CASE WHEN is_default = 1 THEN 1 ELSE NULL END) STORED
                    ");
                }

                // Unique index on (branch_id, is_default_enforced) effectively enforces
                // only one row with is_default = true per branch, while allowing many false.
                DB::statement("
                    CREATE UNIQUE INDEX warehouses_branch_default_unique
                    ON warehouses (branch_id, is_default_enforced)
                ");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('warehouses')) {
            return;
        }

        Schema::table('warehouses', function (Blueprint $table): void {
            if (Schema::hasIndex('warehouses', ['company_id', 'code'])) {
                $table->dropIndex('warehouses_company_id_code_index');
            }

            if (Schema::hasColumn('warehouses', 'slug')) {
                $table->dropColumn('slug');
            }
        });

        if (DB::getDriverName() === 'mysql') {
            try {
                DB::statement('DROP INDEX warehouses_branch_default_unique ON warehouses');
            } catch (\Throwable $e) {
                // ignore if index does not exist
            }

            if (Schema::hasColumn('warehouses', 'is_default_enforced')) {
                DB::statement('ALTER TABLE warehouses DROP COLUMN is_default_enforced');
            }
        }
    }
};

