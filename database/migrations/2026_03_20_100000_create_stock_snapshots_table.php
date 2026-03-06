<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_snapshots')) {
            Schema::create('stock_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $table->date('snapshot_date');
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->unsignedBigInteger('variant_id')->nullable();
                $table->decimal('quantity', 18, 4)->default(0);
                $table->timestamps();
            });
        }

        Schema::table('stock_snapshots', function (Blueprint $table): void {
            if (! $this->indexExists('stock_snapshots', 'stock_snapshots_unique')) {
                $table->unique(['snapshot_date', 'product_id', 'warehouse_id', 'variant_id'], 'stock_snapshots_unique');
            }
            if (! $this->indexExists('stock_snapshots', 'stock_snapshots_company_date_index')) {
                $table->index(['company_id', 'snapshot_date'], 'stock_snapshots_company_date_index');
            }
            if (! $this->indexExists('stock_snapshots', 'stock_snapshots_product_date_index')) {
                $table->index(['product_id', 'snapshot_date'], 'stock_snapshots_product_date_index');
            }
        });
    }

    private function indexExists(string $table, string $name): bool
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            $result = Schema::getConnection()->select(
                "SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1",
                [$table, $name]
            );
            return count($result) > 0;
        }
        return false;
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_snapshots');
    }
};
