<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        Schema::table('sales', function (Blueprint $table): void {
            if (! Schema::hasColumn('sales', 'payment_status')) {
                $table->string('payment_status', 20)->default('unpaid')->after('status');
            }
            if (! Schema::hasColumn('sales', 'subtotal')) {
                $table->decimal('subtotal', 15, 2)->default(0)->after('total');
            }
            if (! Schema::hasColumn('sales', 'discount_total')) {
                $table->decimal('discount_total', 15, 2)->default(0)->after('subtotal');
            }
            if (! Schema::hasColumn('sales', 'tax_total')) {
                $table->decimal('tax_total', 15, 2)->default(0)->after('discount_total');
            }
            if (! Schema::hasColumn('sales', 'grand_total')) {
                $table->decimal('grand_total', 15, 2)->default(0)->after('tax_total');
            }
            if (! Schema::hasColumn('sales', 'notes')) {
                $table->text('notes')->nullable()->after('grand_total');
            }
            if (! Schema::hasColumn('sales', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('sales', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        // Backfill: grand_total and subtotal = total for existing rows
        \Illuminate\Support\Facades\DB::table('sales')
            ->where('grand_total', 0)
            ->where('subtotal', 0)
            ->update([
                'grand_total' => \Illuminate\Support\Facades\DB::raw('total'),
                'subtotal' => \Illuminate\Support\Facades\DB::raw('total'),
            ]);

        // Backfill payment_status from paid_amount and total
        $driver = \Illuminate\Support\Facades\DB::getDriverName();
        if ($driver === 'mysql') {
            \Illuminate\Support\Facades\DB::statement("
                UPDATE sales SET payment_status = CASE
                    WHEN total <= 0 THEN 'paid'
                    WHEN paid_amount <= 0 THEN 'unpaid'
                    WHEN paid_amount >= total THEN 'paid'
                    ELSE 'partial'
                END
            ");
        } else {
            foreach (\Illuminate\Support\Facades\DB::table('sales')->get() as $sale) {
                $paid = (float) $sale->paid_amount;
                $total = (float) $sale->total;
                $status = $total <= 0 ? 'paid' : ($paid <= 0 ? 'unpaid' : ($paid >= $total ? 'paid' : 'partial'));
                \Illuminate\Support\Facades\DB::table('sales')->where('id', $sale->id)->update(['payment_status' => $status]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        Schema::table('sales', function (Blueprint $table): void {
            if (Schema::hasColumn('sales', 'payment_status')) {
                $table->dropColumn('payment_status');
            }
            if (Schema::hasColumn('sales', 'subtotal')) {
                $table->dropColumn('subtotal');
            }
            if (Schema::hasColumn('sales', 'discount_total')) {
                $table->dropColumn('discount_total');
            }
            if (Schema::hasColumn('sales', 'tax_total')) {
                $table->dropColumn('tax_total');
            }
            if (Schema::hasColumn('sales', 'grand_total')) {
                $table->dropColumn('grand_total');
            }
            if (Schema::hasColumn('sales', 'notes')) {
                $table->dropColumn('notes');
            }
            if (Schema::hasColumn('sales', 'updated_by')) {
                $table->dropForeign(['updated_by']);
                $table->dropColumn('updated_by');
            }
            if (Schema::hasColumn('sales', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
