<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_warranties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('warranty_id')->constrained('warranties')->cascadeOnDelete();
            $table->boolean('is_default')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'warranty_id']);
            $table->index('product_id');
            $table->index('warranty_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_warranties');
    }
};

