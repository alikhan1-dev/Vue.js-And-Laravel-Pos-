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
        // Update warehouse unique constraint to (company_id, branch_id, code).
        if (Schema::hasTable('warehouses')) {
            Schema::table('warehouses', function (Blueprint $table): void {
                try {
                    $table->dropUnique('warehouses_company_id_code_unique');
                } catch (\Throwable $e) {
                    // Index may already be changed or missing; ignore.
                }

                if (Schema::hasColumn('warehouses', 'company_id') && Schema::hasColumn('warehouses', 'branch_id')) {
                    $table->unique(
                        ['company_id', 'branch_id', 'code'],
                        'warehouses_company_id_branch_id_code_unique'
                    );
                }
            });
        }

        // Add composite unique for (company_id, email) on users for multi-tenant safety,
        // and drop the global unique(email) so the same email can exist in multiple companies.
        if (Schema::hasTable('users')
            && Schema::hasColumn('users', 'company_id')
            && Schema::hasColumn('users', 'email')
        ) {
            Schema::table('users', function (Blueprint $table): void {
                try {
                    $table->dropUnique('users_email_unique');
                } catch (\Throwable $e) {
                    // Index may not exist (already dropped or different name), ignore.
                }

                $table->unique(['company_id', 'email'], 'users_company_id_email_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('warehouses')) {
            Schema::table('warehouses', function (Blueprint $table): void {
                try {
                    $table->dropUnique('warehouses_company_id_branch_id_code_unique');
                } catch (\Throwable $e) {
                }
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table): void {
                try {
                    $table->dropUnique('users_company_id_email_unique');
                } catch (\Throwable $e) {
                }

                // Do not re-create global UNIQUE(email); we keep true multi-tenant semantics.
            });
        }
    }
};

