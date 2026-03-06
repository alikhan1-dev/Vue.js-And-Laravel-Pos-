<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Branch belongs to a Company (tenant) and has many Warehouses.
 * All queries are tenant-aware via global scope: only branches of the
 * logged-in user's company are returned in HTTP context.
 */
class Branch extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'code',
        'address',
        'timezone',
        'default_warehouse_id',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Tenant isolation: filter branches by the authenticated user's company_id.
     * Disabled in console (e.g. seeders, tinker) so cross-tenant operations work.
     */
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

    /** Branch belongs to one Company. */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function defaultWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id');
    }

    /** Branch has many Warehouses. */
    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }
}
