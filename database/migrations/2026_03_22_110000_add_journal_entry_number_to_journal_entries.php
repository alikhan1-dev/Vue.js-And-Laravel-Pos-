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
            if (! Schema::hasColumn('journal_entries', 'journal_entry_number')) {
                $table->string('journal_entry_number', 50)
                    ->nullable()
                    ->after('company_id');

                $table->unique(
                    ['company_id', 'journal_entry_number'],
                    'journal_entries_company_entry_number_unique'
                );
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('journal_entries')) {
            return;
        }

        Schema::table('journal_entries', function (Blueprint $table): void {
            if (Schema::hasColumn('journal_entries', 'journal_entry_number')) {
                $table->dropUnique('journal_entries_company_entry_number_unique');
                $table->dropColumn('journal_entry_number');
            }
        });
    }
};

