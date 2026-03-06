<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('short_name', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'short_name']);
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
