<?php

namespace App\Models;

use App\Enums\WarrantyType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warranty extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'duration_months',
        'type',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'duration_months' => 'integer',
            'type' => WarrantyType::class,
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('company', function (Builder $builder): void {
            if (app()->runningInConsole()) {
                return;
            }

            if (auth()->check()) {
                $builder->where('company_id', auth()->user()->company_id);
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function productWarranties(): HasMany
    {
        return $this->hasMany(ProductWarranty::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(WarrantyRegistration::class);
    }
}

