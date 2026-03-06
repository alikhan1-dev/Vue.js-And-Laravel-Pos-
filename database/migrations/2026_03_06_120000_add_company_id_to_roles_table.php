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
        Schema::table('roles', function (Blueprint $table): void {
            if (! Schema::hasColumn('roles', 'company_id')) {
                $table->foreignId('company_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('companies')
                    ->cascadeOnDelete();
            }

            // Replace the global unique(name, guard_name) with a company-scoped unique key.
            $table->dropUnique('roles_name_guard_name_unique');
            $table->unique(['company_id', 'name', 'guard_name'], 'roles_company_name_guard_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            if (Schema::hasColumn('roles', 'company_id')) {
                $table->dropForeign(['company_id']);
                $table->dropColumn('company_id');
            }

            $table->dropUnique('roles_company_name_guard_unique');
            $table->unique(['name', 'guard_name']);
        });
    }
};

