<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('sale_lines')) {
            return;
        }

        Schema::table('sale_lines', function (Blueprint $table): void {
            if (! Schema::hasColumn('sale_lines', 'variant_id')) {
                $table->foreignId('variant_id')
                    ->nullable()
                    ->after('product_id')
                    ->constrained('product_variants')
                    ->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('sale_lines')) {
            return;
        }

        Schema::table('sale_lines', function (Blueprint $table): void {
            if (Schema::hasColumn('sale_lines', 'variant_id')) {
                $table->dropForeign(['variant_id']);
                $table->dropColumn('variant_id');
            }
        });
    }
};

