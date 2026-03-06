<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Damage/loss reports for warehouse auditing. damage_out movements can reference this.
     */
    public function up(): void
    {
        Schema::create('damage_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->string('report_number', 50);
            $table->dateTime('report_date');
            $table->string('reason', 100)->nullable(); // damage, loss, expiry, etc.
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'report_number']);
            $table->index(['warehouse_id', 'report_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('damage_reports');
    }
};
