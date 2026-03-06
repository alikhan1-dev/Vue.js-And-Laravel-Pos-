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
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'deleted_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }

        if (Schema::hasTable('branches') && ! Schema::hasColumn('branches', 'deleted_at')) {
            Schema::table('branches', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }

        if (Schema::hasTable('warehouses') && ! Schema::hasColumn('warehouses', 'deleted_at')) {
            Schema::table('warehouses', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'deleted_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }

        if (Schema::hasTable('branches') && Schema::hasColumn('branches', 'deleted_at')) {
            Schema::table('branches', function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }

        if (Schema::hasTable('warehouses') && Schema::hasColumn('warehouses', 'deleted_at')) {
            Schema::table('warehouses', function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }
    }
};

