<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type', 100);
            $table->uuid('event_id')->unique();
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->index('event_type');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_events');
    }
};
