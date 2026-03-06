<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        Schema::table('sales', function (Blueprint $table): void {
            if (! Schema::hasColumn('sales', 'paid_amount')) {
                $table->decimal('paid_amount', 15, 2)->default(0)->after('total');
            }
            if (! Schema::hasColumn('sales', 'due_amount')) {
                $table->decimal('due_amount', 15, 2)->default(0)->after('paid_amount');
            }
        });

        // Backfill: paid_amount = sum(completed payments), due_amount = total - paid_amount
        if (Schema::hasTable('payments')) {
            $driver = DB::getDriverName();
            if ($driver === 'mysql') {
                DB::statement("
                    UPDATE sales s
                    LEFT JOIN (
                        SELECT sale_id, SUM(amount) AS paid
                        FROM payments
                        WHERE status = 'completed' AND deleted_at IS NULL
                        GROUP BY sale_id
                    ) p ON p.sale_id = s.id
                    SET s.paid_amount = COALESCE(p.paid, 0),
                        s.due_amount = s.total - COALESCE(p.paid, 0)
                ");
            } else {
                $sales = DB::table('sales')->select('id', 'total')->get();
                foreach ($sales as $sale) {
                    $paid = DB::table('payments')
                        ->where('sale_id', $sale->id)
                        ->where('status', 'completed')
                        ->sum('amount');
                    $due = (float) $sale->total - (float) $paid;
                    DB::table('sales')->where('id', $sale->id)->update([
                        'paid_amount' => $paid,
                        'due_amount' => max(0, $due),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        Schema::table('sales', function (Blueprint $table): void {
            if (Schema::hasColumn('sales', 'paid_amount')) {
                $table->dropColumn('paid_amount');
            }
            if (Schema::hasColumn('sales', 'due_amount')) {
                $table->dropColumn('due_amount');
            }
        });
    }
};
