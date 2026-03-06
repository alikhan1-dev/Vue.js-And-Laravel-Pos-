<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_bundles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bundle_product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('component_product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('quantity', 15, 4); // component quantity per 1 unit of bundle
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['bundle_product_id', 'component_product_id']);
            $table->index('bundle_product_id');
            $table->index('component_product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_bundles');
    }
};

