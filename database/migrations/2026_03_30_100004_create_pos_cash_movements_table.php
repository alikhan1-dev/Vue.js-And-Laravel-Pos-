<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cash movement log for POS sessions: cash in/out, pay-in, pay-out, drawer
 * open events, float adjustments, and shift handover notes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->foreignId('pos_session_id')->constrained('pos_sessions');
            $table->string('type', 30);
            $table->decimal('amount', 15, 2);
            $table->string('reason', 255)->nullable();
            $table->string('reference', 100)->nullable();
            $table->foreignId('payment_id')->nullable()->constrained('payments');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['pos_session_id', 'type']);
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_cash_movements');
    }
};
