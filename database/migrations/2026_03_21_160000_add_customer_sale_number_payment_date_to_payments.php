<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table): void {
            if (! Schema::hasColumn('payments', 'customer_id')) {
                $table->unsignedBigInteger('customer_id')->nullable()->after('sale_id');
                $table->index('customer_id', 'payments_customer_id_index');
            }
            if (! Schema::hasColumn('payments', 'sale_number')) {
                $table->string('sale_number', 32)->nullable()->after('customer_id');
            }
            if (! Schema::hasColumn('payments', 'payment_date')) {
                $table->date('payment_date')->nullable()->after('exchange_rate');
                $table->index('payment_date', 'payments_payment_date_index');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table): void {
            if (Schema::hasColumn('payments', 'customer_id')) {
                $table->dropIndex('payments_customer_id_index');
                $table->dropColumn('customer_id');
            }
            if (Schema::hasColumn('payments', 'sale_number')) {
                $table->dropColumn('sale_number');
            }
            if (Schema::hasColumn('payments', 'payment_date')) {
                $table->dropIndex('payments_payment_date_index');
                $table->dropColumn('payment_date');
            }
        });
    }
};
