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
        // Branch enhancements: default warehouse and timezone
        if (Schema::hasTable('branches')) {
            Schema::table('branches', function (Blueprint $table): void {
                if (! Schema::hasColumn('branches', 'default_warehouse_id')) {
                    $table->foreignId('default_warehouse_id')
                        ->nullable()
                        ->after('company_id')
                        ->constrained('warehouses')
                        ->nullOnDelete();
                }

                if (! Schema::hasColumn('branches', 'timezone')) {
                    $table->string('timezone', 64)
                        ->nullable()
                        ->after('address');
                }
            });
        }

        // Warehouse enhancements: type and transfer readiness flags.
        if (Schema::hasTable('warehouses')) {
            Schema::table('warehouses', function (Blueprint $table): void {
                if (! Schema::hasColumn('warehouses', 'type')) {
                    $table->string('type', 32)
                        ->default('store')
                        ->after('code');
                }
                if (! Schema::hasColumn('warehouses', 'allow_sales')) {
                    $table->boolean('allow_sales')
                        ->default(true)
                        ->after('is_active');
                }
                if (! Schema::hasColumn('warehouses', 'allow_purchases')) {
                    $table->boolean('allow_purchases')
                        ->default(true)
                        ->after('allow_sales');
                }

                if (! Schema::hasColumn('warehouses', 'is_default')) {
                    $table->boolean('is_default')
                        ->default(false)
                        ->after('allow_purchases');
                }

                if (! Schema::hasColumn('warehouses', 'capacity_items')) {
                    $table->unsignedBigInteger('capacity_items')
                        ->nullable()
                        ->after('is_default');
                }

                if (! Schema::hasColumn('warehouses', 'capacity_weight')) {
                    $table->decimal('capacity_weight', 15, 3)
                        ->nullable()
                        ->after('capacity_items');
                }

                if (! Schema::hasColumn('warehouses', 'latitude')) {
                    $table->decimal('latitude', 10, 7)
                        ->nullable()
                        ->after('capacity_weight');
                }

                if (! Schema::hasColumn('warehouses', 'longitude')) {
                    $table->decimal('longitude', 11, 7)
                        ->nullable()
                        ->after('latitude');
                }
            });

            // Convert type to ENUM on MySQL to prevent invalid values.
            if (DB::getDriverName() === 'mysql') {
                DB::statement("
                    ALTER TABLE warehouses
                    MODIFY COLUMN type ENUM('store','distribution','transit','returns','damaged','production')
                    NOT NULL DEFAULT 'store'
                ");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('branches')) {
            Schema::table('branches', function (Blueprint $table): void {
                if (Schema::hasColumn('branches', 'default_warehouse_id')) {
                    $table->dropForeign(['default_warehouse_id']);
                    $table->dropColumn('default_warehouse_id');
                }
                if (Schema::hasColumn('branches', 'timezone')) {
                    $table->dropColumn('timezone');
                }
            });
        }

        if (Schema::hasTable('warehouses')) {
            Schema::table('warehouses', function (Blueprint $table): void {
                if (Schema::hasColumn('warehouses', 'type')) {
                    $table->dropColumn('type');
                }
                if (Schema::hasColumn('warehouses', 'allow_sales')) {
                    $table->dropColumn('allow_sales');
                }
                if (Schema::hasColumn('warehouses', 'allow_purchases')) {
                    $table->dropColumn('allow_purchases');
                }
                if (Schema::hasColumn('warehouses', 'is_default')) {
                    $table->dropColumn('is_default');
                }
                if (Schema::hasColumn('warehouses', 'capacity_items')) {
                    $table->dropColumn('capacity_items');
                }
                if (Schema::hasColumn('warehouses', 'capacity_weight')) {
                    $table->dropColumn('capacity_weight');
                }
                if (Schema::hasColumn('warehouses', 'latitude')) {
                    $table->dropColumn('latitude');
                }
                if (Schema::hasColumn('warehouses', 'longitude')) {
                    $table->dropColumn('longitude');
                }
            });
        }
    }
};

