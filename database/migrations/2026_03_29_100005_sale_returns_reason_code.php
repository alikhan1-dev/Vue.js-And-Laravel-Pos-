<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sale_returns') && ! Schema::hasColumn('sale_returns', 'return_reason_code')) {
            Schema::table('sale_returns', function (Blueprint $table): void {
                $table->string('return_reason_code', 30)->nullable()->after('reason');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sale_returns') && Schema::hasColumn('sale_returns', 'return_reason_code')) {
            Schema::table('sale_returns', fn (Blueprint $table) => $table->dropColumn('return_reason_code'));
        }
    }
};
