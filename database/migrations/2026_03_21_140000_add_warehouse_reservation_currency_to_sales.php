<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table): void {
                if (! Schema::hasColumn('sales', 'currency')) {
                    $table->string('currency', 10)->default('PKR')->after('total');
                }
                if (! Schema::hasColumn('sales', 'exchange_rate')) {
                    $table->decimal('exchange_rate', 15, 6)->default(1.000000)->after('currency');
                }
            });
        }

        if (Schema::hasTable('sale_lines')) {
            Schema::table('sale_lines', function (Blueprint $table): void {
                if (! Schema::hasColumn('sale_lines', 'warehouse_id')) {
                    $table->foreignId('warehouse_id')
                        ->nullable()
                        ->after('sale_id')
                        ->constrained('warehouses')
                        ->nullOnDelete();
                }
                if (! Schema::hasColumn('sale_lines', 'reservation_id')) {
                    $table->foreignId('reservation_id')
                        ->nullable()
                        ->after('stock_movement_id')
                        ->constrained('stock_reservations')
                        ->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sale_lines')) {
            Schema::table('sale_lines', function (Blueprint $table): void {
                if (Schema::hasColumn('sale_lines', 'warehouse_id')) {
                    $table->dropForeign(['warehouse_id']);
                    $table->dropColumn('warehouse_id');
                }
                if (Schema::hasColumn('sale_lines', 'reservation_id')) {
                    $table->dropForeign(['reservation_id']);
                    $table->dropColumn('reservation_id');
                }
            });
        }

        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table): void {
                if (Schema::hasColumn('sales', 'currency')) {
                    $table->dropColumn('currency');
                }
                if (Schema::hasColumn('sales', 'exchange_rate')) {
                    $table->dropColumn('exchange_rate');
                }
            });
        }
    }
};
