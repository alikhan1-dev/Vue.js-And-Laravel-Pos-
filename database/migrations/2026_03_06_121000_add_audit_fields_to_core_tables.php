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
        // branches: created_by / updated_by
        if (Schema::hasTable('branches')) {
            Schema::table('branches', function (Blueprint $table): void {
                if (! Schema::hasColumn('branches', 'created_by')) {
                    $table->foreignId('created_by')
                        ->nullable()
                        ->after('is_active')
                        ->constrained('users')
                        ->nullOnDelete();
                }
                if (! Schema::hasColumn('branches', 'updated_by')) {
                    $table->foreignId('updated_by')
                        ->nullable()
                        ->after('created_by')
                        ->constrained('users')
                        ->nullOnDelete();
                }
            });
        }

        // warehouses: created_by / updated_by
        if (Schema::hasTable('warehouses')) {
            Schema::table('warehouses', function (Blueprint $table): void {
                if (! Schema::hasColumn('warehouses', 'created_by')) {
                    $table->foreignId('created_by')
                        ->nullable()
                        ->after('is_active')
                        ->constrained('users')
                        ->nullOnDelete();
                }
                if (! Schema::hasColumn('warehouses', 'updated_by')) {
                    $table->foreignId('updated_by')
                        ->nullable()
                        ->after('created_by')
                        ->constrained('users')
                        ->nullOnDelete();
                }
            });
        }

        // products: created_by / updated_by
        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table): void {
                if (! Schema::hasColumn('products', 'created_by')) {
                    $table->foreignId('created_by')
                        ->nullable()
                        ->after('is_active')
                        ->constrained('users')
                        ->nullOnDelete();
                }
                if (! Schema::hasColumn('products', 'updated_by')) {
                    $table->foreignId('updated_by')
                        ->nullable()
                        ->after('created_by')
                        ->constrained('users')
                        ->nullOnDelete();
                }
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
                if (Schema::hasColumn('branches', 'created_by')) {
                    $table->dropForeign(['created_by']);
                    $table->dropColumn('created_by');
                }
                if (Schema::hasColumn('branches', 'updated_by')) {
                    $table->dropForeign(['updated_by']);
                    $table->dropColumn('updated_by');
                }
            });
        }

        if (Schema::hasTable('warehouses')) {
            Schema::table('warehouses', function (Blueprint $table): void {
                if (Schema::hasColumn('warehouses', 'created_by')) {
                    $table->dropForeign(['created_by']);
                    $table->dropColumn('created_by');
                }
                if (Schema::hasColumn('warehouses', 'updated_by')) {
                    $table->dropForeign(['updated_by']);
                    $table->dropColumn('updated_by');
                }
            });
        }

        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table): void {
                if (Schema::hasColumn('products', 'created_by')) {
                    $table->dropForeign(['created_by']);
                    $table->dropColumn('created_by');
                }
                if (Schema::hasColumn('products', 'updated_by')) {
                    $table->dropForeign(['updated_by']);
                    $table->dropColumn('updated_by');
                }
            });
        }
    }
};

