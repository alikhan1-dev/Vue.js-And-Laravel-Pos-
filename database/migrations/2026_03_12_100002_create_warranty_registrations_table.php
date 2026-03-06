<?php

use App\Enums\WarrantyStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranty_registrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('sale_line_id')->constrained('sale_lines')->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('serial_id')->nullable()->constrained('product_serials')->nullOnDelete();
            $table->foreignId('warranty_id')->constrained('warranties')->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', WarrantyStatus::values())->default(WarrantyStatus::Active->value);
            $table->timestamps();

            $table->index('company_id');
            $table->index('sale_id');
            $table->index('product_id');
            $table->index('serial_id');
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_registrations');
    }
};

