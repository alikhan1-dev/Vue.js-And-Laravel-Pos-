<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Branch codes: remove global unique(code), keep per-company unique(company_id, code).
        if (Schema::hasTable('branches') && Schema::hasColumn('branches', 'code')) {
            Schema::table('branches', function (Blueprint $table): void {
                try {
                    $table->dropUnique('branches_code_unique');
                } catch (\Throwable $e) {
                    // Index may already be removed or have a different name; ignore.
                }
            });
        }

        // Warehouse branch FK: restrict deletes so branches with warehouses cannot be deleted.
        if (Schema::hasTable('warehouses') && Schema::hasColumn('warehouses', 'branch_id')) {
            Schema::table('warehouses', function (Blueprint $table): void {
                try {
                    $table->dropForeign(['branch_id']);
                } catch (\Throwable $e) {
                    // Foreign key might already be adjusted; ignore.
                }

                $table->foreign('branch_id')
                    ->references('id')
                    ->on('branches')
                    ->restrictOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore global unique(code) on branches if needed.
        if (Schema::hasTable('branches') && Schema::hasColumn('branches', 'code')) {
            Schema::table('branches', function (Blueprint $table): void {
                $table->unique('code', 'branches_code_unique');
            });
        }

        // Restore cascade delete behavior on warehouses.branch_id for backwards compatibility.
        if (Schema::hasTable('warehouses') && Schema::hasColumn('warehouses', 'branch_id')) {
            Schema::table('warehouses', function (Blueprint $table): void {
                try {
                    $table->dropForeign(['branch_id']);
                } catch (\Throwable $e) {
                }

                $table->foreign('branch_id')
                    ->references('id')
                    ->on('branches')
                    ->cascadeOnDelete();
            });
        }
    }
};

