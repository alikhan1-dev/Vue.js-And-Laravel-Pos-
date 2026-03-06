<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional unit of measure (UOM) for products (e.g. piece, kg, box).
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('uom', 20)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('uom');
        });
    }
};
