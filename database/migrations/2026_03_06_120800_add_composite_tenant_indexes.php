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
        // users: composite tenant + branch index
        if (Schema::hasTable('users')
            && Schema::hasColumn('users', 'company_id')
            && Schema::hasColumn('users', 'branch_id')
        ) {
            Schema::table('users', function (Blueprint $table): void {
                $table->index(['company_id', 'branch_id'], 'users_company_id_branch_id_index');
            });
        }

        // warehouses: composite tenant + branch index
        if (Schema::hasTable('warehouses')
            && Schema::hasColumn('warehouses', 'company_id')
            && Schema::hasColumn('warehouses', 'branch_id')
        ) {
            Schema::table('warehouses', function (Blueprint $table): void {
                $table->index(['company_id', 'branch_id'], 'warehouses_company_id_branch_id_index');
            });
        }

        // branches: keep single-column company_id index (already added), no composite needed here.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table): void {
                try {
                    $table->dropIndex('users_company_id_branch_id_index');
                } catch (\Throwable $e) {
                    // ignore if index missing
                }
            });
        }

        if (Schema::hasTable('warehouses')) {
            Schema::table('warehouses', function (Blueprint $table): void {
                try {
                    $table->dropIndex('warehouses_company_id_branch_id_index');
                } catch (\Throwable $e) {
                    // ignore if index missing
                }
            });
        }
    }
};

