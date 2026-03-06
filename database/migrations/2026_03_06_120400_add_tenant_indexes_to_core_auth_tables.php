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
        // users: tenant + branch indexes
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table): void {
                if (Schema::hasColumn('users', 'company_id')) {
                    $table->index('company_id', 'users_company_id_index');
                }
                if (Schema::hasColumn('users', 'branch_id')) {
                    $table->index('branch_id', 'users_branch_id_index');
                }
            });
        }

        // branches: tenant index (already added in other migration, but safe if order differs)
        if (Schema::hasTable('branches')) {
            Schema::table('branches', function (Blueprint $table): void {
                if (Schema::hasColumn('branches', 'company_id')) {
                    $table->index('company_id', 'branches_company_id_index_alt');
                }
            });
        }

        // warehouses: tenant + branch indexes (added earlier, but keep alt names if needed)
        if (Schema::hasTable('warehouses')) {
            Schema::table('warehouses', function (Blueprint $table): void {
                if (Schema::hasColumn('warehouses', 'company_id')) {
                    $table->index('company_id', 'warehouses_company_id_index_alt');
                }
                if (Schema::hasColumn('warehouses', 'branch_id')) {
                    $table->index('branch_id', 'warehouses_branch_id_index_alt');
                }
            });
        }

        // roles: tenant index
        if (Schema::hasTable('roles')) {
            Schema::table('roles', function (Blueprint $table): void {
                if (Schema::hasColumn('roles', 'company_id')) {
                    $table->index('company_id', 'roles_company_id_index');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table): void {
                try {
                    $table->dropIndex('users_company_id_index');
                } catch (\Throwable $e) {
                }
                try {
                    $table->dropIndex('users_branch_id_index');
                } catch (\Throwable $e) {
                }
            });
        }

        if (Schema::hasTable('branches')) {
            Schema::table('branches', function (Blueprint $table): void {
                try {
                    $table->dropIndex('branches_company_id_index_alt');
                } catch (\Throwable $e) {
                }
            });
        }

        if (Schema::hasTable('warehouses')) {
            Schema::table('warehouses', function (Blueprint $table): void {
                try {
                    $table->dropIndex('warehouses_company_id_index_alt');
                } catch (\Throwable $e) {
                }
                try {
                    $table->dropIndex('warehouses_branch_id_index_alt');
                } catch (\Throwable $e) {
                }
            });
        }

        if (Schema::hasTable('roles')) {
            Schema::table('roles', function (Blueprint $table): void {
                try {
                    $table->dropIndex('roles_company_id_index');
                } catch (\Throwable $e) {
                }
            });
        }
    }
};

