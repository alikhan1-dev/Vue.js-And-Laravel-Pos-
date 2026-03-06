<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Legacy migration for payment_lines.method ENUM.
     * Column has been removed in favour of payment_method_id, so this is a no-op.
     */
    public function up(): void
    {
        // no-op
    }

    public function down(): void
    {
        // no-op
    }
};
