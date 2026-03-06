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
        // Branch codes unique per company, plus company index.
        if (Schema::hasTable('branches')) {
            Schema::table('branches', function (Blueprint $table): void {
                $table->index('company_id', 'branches_company_id_index');
                $table->unique(['company_id', 'code'], 'branches_company_id_code_unique');
            });
        }

        // Warehouses: add company_id + unique(company_id, code) + indexes.
        if (Schema::hasTable('warehouses')) {
            Schema::table('warehouses', function (Blueprint $table): void {
                if (! Schema::hasColumn('warehouses', 'company_id')) {
                    $table->foreignId('company_id')
                        ->nullable()
                        ->after('id')
                        ->constrained('companies')
                        ->cascadeOnDelete();
                }

                $table->index('company_id', 'warehouses_company_id_index');
                $table->index('branch_id', 'warehouses_branch_id_index');
                $table->unique(['company_id', 'code'], 'warehouses_company_id_code_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('branches')) {
            Schema::table('branches', function (Blueprint $table): void {
                $table->dropUnique('branches_company_id_code_unique');
                $table->dropIndex('branches_company_id_index');
            });
        }

        if (Schema::hasTable('warehouses')) {
            Schema::table('warehouses', function (Blueprint $table): void {
                $table->dropUnique('warehouses_company_id_code_unique');
                $table->dropIndex('warehouses_company_id_index');
                $table->dropIndex('warehouses_branch_id_index');

                if (Schema::hasColumn('warehouses', 'company_id')) {
                    $table->dropForeign(['company_id']);
                    $table->dropColumn('company_id');
                }
            });
        }
    }
};

