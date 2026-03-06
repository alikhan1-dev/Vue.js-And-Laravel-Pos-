<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Legacy migration to convert journal_entries.reference_type to ENUM.
     * Kept as a no-op for new installs to avoid schema mismatch with base create migration.
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

