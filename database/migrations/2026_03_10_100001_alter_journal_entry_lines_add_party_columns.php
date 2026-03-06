<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable()->after('account_id');
            $table->unsignedBigInteger('supplier_id')->nullable()->after('customer_id');

            $table->index('customer_id');
            $table->index('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->dropIndex(['customer_id']);
            $table->dropIndex(['supplier_id']);
            $table->dropColumn(['customer_id', 'supplier_id']);
        });
    }
};

