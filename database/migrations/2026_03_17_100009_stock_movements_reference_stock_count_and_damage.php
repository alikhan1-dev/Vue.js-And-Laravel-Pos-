<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allow adjustment movements to reference stock_count_id; damage_out to reference damage_report_id.
     */
    public function up(): void
    {
        if (! Schema::hasTable('stock_movements')) {
            return;
        }

        Schema::table('stock_movements', function (Blueprint $table): void {
            if (! Schema::hasColumn('stock_movements', 'stock_count_id')) {
                $table->foreignId('stock_count_id')->nullable()->after('reference_id')->constrained('stock_counts')->nullOnDelete();
            }
            if (! Schema::hasColumn('stock_movements', 'damage_report_id')) {
                $table->foreignId('damage_report_id')->nullable()->after('stock_count_id')->constrained('damage_reports')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_movements')) {
            return;
        }

        Schema::table('stock_movements', function (Blueprint $table): void {
            if (Schema::hasColumn('stock_movements', 'stock_count_id')) {
                $table->dropForeign(['stock_count_id']);
            }
            if (Schema::hasColumn('stock_movements', 'damage_report_id')) {
                $table->dropForeign(['damage_report_id']);
            }
        });
    }
};
