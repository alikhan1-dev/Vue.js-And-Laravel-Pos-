<?php

namespace App\Models;

use App\Enums\WarrantyClaimStatus;
use App\Enums\WarrantyClaimType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarrantyClaim extends Model
{
    use HasFactory;

    protected $fillable = [
        'warranty_registration_id',
        'claim_number',
        'claim_type',
        'description',
        'status',
        'approved_by',
        'resolution_notes',
    ];

    protected function casts(): array
    {
        return [
            'claim_type' => WarrantyClaimType::class,
            'status' => WarrantyClaimStatus::class,
        ];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(WarrantyRegistration::class, 'warranty_registration_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}

