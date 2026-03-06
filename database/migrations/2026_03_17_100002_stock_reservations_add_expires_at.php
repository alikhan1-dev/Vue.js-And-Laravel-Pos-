<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add expires_at to reservations so dead reservations can be auto-released.
     */
    public function up(): void
    {
        if (! Schema::hasTable('stock_reservations')) {
            return;
        }

        Schema::table('stock_reservations', function (Blueprint $table): void {
            if (! Schema::hasColumn('stock_reservations', 'expires_at')) {
                $table->dateTime('expires_at')->nullable()->after('reference_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_reservations')) {
            return;
        }

        Schema::table('stock_reservations', function (Blueprint $table): void {
            if (Schema::hasColumn('stock_reservations', 'expires_at')) {
                $table->dropColumn('expires_at');
            }
        });
    }
};
