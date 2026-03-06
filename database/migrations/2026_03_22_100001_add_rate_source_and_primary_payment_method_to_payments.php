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
            if (! Schema::hasColumn('payments', 'rate_source')) {
                $table->string('rate_source', 50)->nullable()->after('exchange_rate');
            }
            if (! Schema::hasColumn('payments', 'primary_payment_method_id')) {
                $table->foreignId('primary_payment_method_id')
                    ->nullable()
                    ->after('currency_id')
                    ->constrained('payment_methods')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table): void {
            if (Schema::hasColumn('payments', 'rate_source')) {
                $table->dropColumn('rate_source');
            }
            if (Schema::hasColumn('payments', 'primary_payment_method_id')) {
                $table->dropForeign(['primary_payment_method_id']);
                $table->dropColumn('primary_payment_method_id');
            }
        });
    }
};
