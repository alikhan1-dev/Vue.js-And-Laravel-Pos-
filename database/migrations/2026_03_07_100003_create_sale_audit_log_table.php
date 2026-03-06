<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit trail for sale lifecycle: status/type changes and stock movement links.
     */
    public function up(): void
    {
        Schema::create('sale_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->string('event', 50); // created, converted_to_sale, return_created, status_changed
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->string('from_type', 20)->nullable();
            $table->string('to_type', 20)->nullable();
            $table->json('metadata')->nullable(); // e.g. stock_movement_ids, return_sale_id
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['sale_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_audit_log');
    }
};
