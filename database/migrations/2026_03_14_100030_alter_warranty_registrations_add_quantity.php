<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('warranty_registrations')) {
            return;
        }

        Schema::table('warranty_registrations', function (Blueprint $table): void {
            if (! Schema::hasColumn('warranty_registrations', 'quantity')) {
                $table->decimal('quantity', 15, 4)->default(1)->after('product_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('warranty_registrations')) {
            return;
        }

        Schema::table('warranty_registrations', function (Blueprint $table): void {
            if (Schema::hasColumn('warranty_registrations', 'quantity')) {
                $table->dropColumn('quantity');
            }
        });
    }
};

