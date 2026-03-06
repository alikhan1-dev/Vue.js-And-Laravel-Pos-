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
            if (! Schema::hasColumn('stock_movements', 'idempotency_key')) {
                $table->string('idempotency_key', 64)->nullable()->after('event_id');
                $table->unique('idempotency_key', 'stock_movements_idempotency_key_unique');
            }
            if (! Schema::hasColumn('stock_movements', 'version')) {
                $table->unsignedTinyInteger('version')->default(1)->after('reason_code');
            }
            if (! Schema::hasColumn('stock_movements', 'reversal_movement_id')) {
                $table->unsignedBigInteger('reversal_movement_id')->nullable()->after('serial_id');
                $table->foreign('reversal_movement_id')->references('id')->on('stock_movements')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_movements')) {
            return;
        }
        Schema::table('stock_movements', function (Blueprint $table): void {
            if (Schema::hasColumn('stock_movements', 'reversal_movement_id')) {
                $table->dropForeign(['reversal_movement_id']);
                $table->dropColumn('reversal_movement_id');
            }
            if (Schema::hasColumn('stock_movements', 'version')) {
                $table->dropColumn('version');
            }
            if (Schema::hasColumn('stock_movements', 'idempotency_key')) {
                $table->dropUnique('stock_movements_idempotency_key_unique');
                $table->dropColumn('idempotency_key');
            }
        });
    }
};
