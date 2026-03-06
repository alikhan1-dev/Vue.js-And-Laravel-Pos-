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
                if (! Schema::hasColumn('sales', 'number')) {
                    $table->string('number', 32)->nullable()->after('id');
                    $table->unique(['company_id', 'number'], 'sales_company_id_number_unique');
                }
            });
        }

        if (Schema::hasTable('sale_lines')) {
            Schema::table('sale_lines', function (Blueprint $table): void {
                if (! Schema::hasColumn('sale_lines', 'line_total')) {
                    $table->decimal('line_total', 15, 2)->nullable()->after('unit_price');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sale_lines')) {
            Schema::table('sale_lines', function (Blueprint $table): void {
                if (Schema::hasColumn('sale_lines', 'line_total')) {
                    $table->dropColumn('line_total');
                }
            });
        }

        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table): void {
                if (Schema::hasColumn('sales', 'number')) {
                    $table->dropUnique('sales_company_id_number_unique');
                    $table->dropColumn('number');
                }
            });
        }
    }
};
