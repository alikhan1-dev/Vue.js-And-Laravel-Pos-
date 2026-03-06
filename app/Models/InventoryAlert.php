<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persistent inventory alert for dashboards and notification workflows.
 * Auto-created by event listeners; resolved manually or by system rules.
 */
class InventoryAlert extends Model
{
    use HasFactory;

    public const TYPE_LOW_STOCK = 'low_stock';

    public const TYPE_EXPIRY_NEAR = 'expiry_near';

    public const TYPE_SERIAL_CONFLICT = 'serial_conflict';

    public const TYPE_NEGATIVE_STOCK = 'negative_stock_attempt';

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_CRITICAL = 'critical';

    protected $fillable = [
        'company_id',
        'product_id',
        'warehouse_id',
        'variant_id',
        'batch_id',
        'alert_type',
        'severity',
        'message',
        'reference_type',
        'reference_id',
        'is_resolved',
        'resolved_at',
        'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'is_resolved' => 'boolean',
            'resolved_at' => 'datetime',
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

    public function resolve(?int $userId = null): bool
    {
        return $this->update([
            'is_resolved' => true,
            'resolved_at' => now(),
            'resolved_by' => $userId ?? auth()->id(),
        ]);
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->where('is_resolved', false);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('alert_type', $type);
    }

    public function scopeCritical(Builder $query): Builder
    {
        return $query->where('severity', self::SEVERITY_CRITICAL);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
