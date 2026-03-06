<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_movements')) {
            return;
        }
        Schema::table('stock_movements', function (Blueprint $table): void {
            if (! Schema::hasColumn('stock_movements', 'reason_code')) {
                $table->string('reason_code', 50)->nullable()->after('source');
                $table->index('reason_code');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_movements')) {
            return;
        }
        Schema::table('stock_movements', function (Blueprint $table): void {
            if (Schema::hasColumn('stock_movements', 'reason_code')) {
                $table->dropColumn('reason_code');
            }
        });
    }
};
