<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Warehouse belongs to a Branch. Tenant isolation is enforced by filtering
 * via the branch's company_id so users only see warehouses of their company.
 */
class Warehouse extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'branch_id',
        'name',
        'slug',
        'code',
        'type',
        'location',
        'status',
        'is_active',
        'allow_sales',
        'allow_purchases',
        'is_default',
        'capacity_items',
        'capacity_weight',
        'latitude',
        'longitude',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'allow_sales' => 'boolean',
            'allow_purchases' => 'boolean',
            'is_default' => 'boolean',
            'capacity_items' => 'integer',
            'capacity_weight' => 'float',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * Tenant isolation: only warehouses whose branch belongs to the
     * authenticated user's company. Disabled in console for seeders/tinker.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('company', function (Builder $builder): void {
            if (app()->runningInConsole()) {
                return;
            }

            if (auth()->check()) {
                $builder->whereHas('branch', function (Builder $q): void {
                    $q->where('company_id', auth()->user()->company_id);
                });
            }
        });

        static::deleting(function (Warehouse $warehouse): void {
            // Only enforce hard-delete safety; soft deletes are allowed for history retention.
            if (method_exists($warehouse, 'isForceDeleting') && $warehouse->isForceDeleting()) {
                app(\App\Services\InventoryDeletionGuard::class)->ensureWarehouseCanBeDeleted($warehouse);
            }
        });

        // Invariant: warehouse.company_id must match branch.company_id when both are set.
        static::saving(function (Warehouse $warehouse): void {
            if ($warehouse->branch_id && $warehouse->company_id) {
                $branch = $warehouse->branch()->withoutGlobalScopes()->first();

                if ($branch && $branch->company_id !== $warehouse->company_id) {
                    throw new \InvalidArgumentException('Warehouse company_id must match branch company_id.');
                }
            }
        });
    }

    /** Warehouse belongs to one Branch. */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
