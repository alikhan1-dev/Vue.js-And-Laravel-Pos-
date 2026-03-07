<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payments') && ! Schema::hasColumn('payments', 'pos_session_id')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->foreignId('pos_session_id')->nullable()->after('warehouse_id')->constrained('pos_sessions')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payments') && Schema::hasColumn('payments', 'pos_session_id')) {
            Schema::table('payments', fn (Blueprint $table) => $table->dropForeign(['pos_session_id']));
        }
    }
};
