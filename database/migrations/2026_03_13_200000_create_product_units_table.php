<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('unit_name', 50);
            $table->decimal('conversion_factor_to_base', 18, 6);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['product_id', 'unit_name']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_units');
    }
};

