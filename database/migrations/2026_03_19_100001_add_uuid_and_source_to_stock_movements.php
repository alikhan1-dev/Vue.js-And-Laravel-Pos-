<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * UUID for API/distributed/microservice use.
     * event_id + source for event-sourcing preparation and debugging.
     */
    public function up(): void
    {
        if (! Schema::hasTable('stock_movements')) {
            return;
        }

        Schema::table('stock_movements', function (Blueprint $table): void {
            if (! Schema::hasColumn('stock_movements', 'uuid')) {
                $table->uuid('uuid')->nullable()->after('id');
                $table->unique('uuid', 'stock_movements_uuid_unique');
            }
            if (! Schema::hasColumn('stock_movements', 'event_id')) {
                $table->uuid('event_id')->nullable()->after('uuid');
                $table->index('event_id', 'stock_movements_event_id_index');
            }
            if (! Schema::hasColumn('stock_movements', 'source')) {
                $table->string('source', 30)->nullable()->after('event_id');
                $table->index('source', 'stock_movements_source_index');
            }
        });

        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement("UPDATE stock_movements SET uuid = UUID() WHERE uuid IS NULL");
        } else {
            DB::statement("UPDATE stock_movements SET uuid = LOWER(HEX(RANDOMBLOB(4)) || '-' || HEX(RANDOMBLOB(2)) || '-4' || SUBSTR(HEX(RANDOMBLOB(2)),2) || '-' || SUBSTR('89ab', 1 + (ABS(RANDOM()) % 4), 1) || SUBSTR(HEX(RANDOMBLOB(2)),2) || '-' || HEX(RANDOMBLOB(6))) WHERE uuid IS NULL");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_movements')) {
            return;
        }

        Schema::table('stock_movements', function (Blueprint $table): void {
            if (Schema::hasColumn('stock_movements', 'uuid')) {
                $table->dropUnique('stock_movements_uuid_unique');
                $table->dropColumn('uuid');
            }
            if (Schema::hasColumn('stock_movements', 'event_id')) {
                $table->dropIndex('stock_movements_event_id_index');
                $table->dropColumn('event_id');
            }
            if (Schema::hasColumn('stock_movements', 'source')) {
                $table->dropIndex('stock_movements_source_index');
                $table->dropColumn('source');
            }
        });
    }
};
