<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend pos_sessions: device tracking, cashier assignment, shift info,
 * and offline sync reconciliation fields.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sessions', function (Blueprint $table) {
            $table->string('device_id', 100)->nullable()->after('branch_id');
            $table->string('device_name', 255)->nullable()->after('device_id');
            $table->foreignId('cashier_id')->nullable()->after('device_name')->constrained('users');
            $table->string('shift', 30)->nullable()->after('cashier_id');
            $table->decimal('cash_difference', 15, 2)->nullable()->after('counted_cash');
            $table->text('close_notes')->nullable()->after('notes');
            $table->boolean('synced')->default(true)->after('close_notes');
        });
    }

    public function down(): void
    {
        Schema::table('pos_sessions', function (Blueprint $table) {
            $table->dropForeign(['cashier_id']);
            $table->dropColumn([
                'device_id', 'device_name', 'cashier_id', 'shift',
                'cash_difference', 'close_notes', 'synced',
            ]);
        });
    }
};
