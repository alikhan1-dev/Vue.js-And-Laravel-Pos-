<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('is_active')->constrained('categories')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->after('category_id')->constrained('brands')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->after('brand_id')->constrained('units')->nullOnDelete();
            $table->string('type', 20)->default('simple')->after('unit_id');
            $table->decimal('cost_price', 15, 4)->default(0)->after('type');
            $table->decimal('selling_price', 15, 4)->default(0)->after('cost_price');
            $table->boolean('track_stock')->default(true)->after('selling_price');
            $table->boolean('track_serial')->default(false)->after('track_stock');
            $table->boolean('track_batch')->default(false)->after('track_serial');
            $table->boolean('allow_negative_stock')->default(false)->after('track_batch');

            $table->index('category_id');
            $table->index('brand_id');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropForeign(['brand_id']);
            $table->dropForeign(['unit_id']);
            $table->dropIndex(['category_id']);
            $table->dropIndex(['brand_id']);
            $table->dropIndex(['type']);
            $table->dropColumn([
                'category_id', 'brand_id', 'unit_id', 'type',
                'cost_price', 'selling_price',
                'track_stock', 'track_serial', 'track_batch', 'allow_negative_stock',
            ]);
        });
    }
};
