<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inventory locking: during stock count, warehouse closing, or audit no transactions allowed.
     */
    public function up(): void
    {
        Schema::create('warehouse_locks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 100)->nullable(); // stock_count, closing, audit
            $table->dateTime('locked_at');
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();

            $table->index(['warehouse_id', 'locked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_locks');
    }
};
