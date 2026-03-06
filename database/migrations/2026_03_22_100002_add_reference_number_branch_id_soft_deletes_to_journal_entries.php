<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('journal_entries')) {
            return;
        }

        Schema::table('journal_entries', function (Blueprint $table): void {
            if (! Schema::hasColumn('journal_entries', 'reference_number')) {
                $table->string('reference_number', 50)->nullable()->after('reference_id');
            }
            if (! Schema::hasColumn('journal_entries', 'branch_id')) {
                $table->foreignId('branch_id')
                    ->nullable()
                    ->after('company_id')
                    ->constrained('branches')
                    ->nullOnDelete();
                $table->index('branch_id', 'journal_entries_branch_id_index');
            }
            if (! Schema::hasColumn('journal_entries', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('journal_entries')) {
            return;
        }

        Schema::table('journal_entries', function (Blueprint $table): void {
            if (Schema::hasColumn('journal_entries', 'reference_number')) {
                $table->dropColumn('reference_number');
            }
            if (Schema::hasColumn('journal_entries', 'branch_id')) {
                $table->dropIndex('journal_entries_branch_id_index');
                $table->dropForeign(['branch_id']);
                $table->dropColumn('branch_id');
            }
            if (Schema::hasColumn('journal_entries', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
