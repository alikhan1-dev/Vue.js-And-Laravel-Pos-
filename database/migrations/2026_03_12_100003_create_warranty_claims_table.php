<?php

use App\Enums\WarrantyClaimStatus;
use App\Enums\WarrantyClaimType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranty_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warranty_registration_id')->constrained('warranty_registrations')->cascadeOnDelete();
            $table->string('claim_number', 50)->unique();
            $table->enum('claim_type', WarrantyClaimType::values());
            $table->text('description');
            $table->enum('status', WarrantyClaimStatus::values())->default(WarrantyClaimStatus::Pending->value);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index('warranty_registration_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_claims');
    }
};

