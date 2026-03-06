<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Legacy migration which added entry_type to old journal_entries schema.
     * New installs already include entry_type from the create migration, so this is a no-op.
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

