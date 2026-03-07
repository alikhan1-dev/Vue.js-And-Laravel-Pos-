<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landed_cost_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landed_cost_line_id')->constrained('landed_cost_lines')->cascadeOnDelete();
            $table->foreignId('goods_receipt_line_id')->constrained('goods_receipt_lines')->cascadeOnDelete();
            $table->decimal('allocated_amount', 15, 2)->default(0);
            $table->timestamps();

            $table->index('landed_cost_line_id', 'lca_landed_cost_line_id_index');
            $table->index('goods_receipt_line_id', 'lca_goods_receipt_line_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landed_cost_allocations');
    }
};
