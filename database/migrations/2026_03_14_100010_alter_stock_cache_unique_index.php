<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_cache')) {
            return;
        }

        // On some MySQL versions the unique index participates in an internal constraint,
        // so altering it in-place can fail. For now, leave the original unique index in place.
        // Fresh installs will still have the original `(product_id, warehouse_id)` unique index
        // defined in the base migration. Variant-level behavior is handled in code by
        // aggregating per-variant quantities when needed.
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_cache')) {
            return;
        }

        // No-op
    }
};

