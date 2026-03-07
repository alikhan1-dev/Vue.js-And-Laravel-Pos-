<?php

namespace App\Models;

use App\Enums\MovementReasonCode;
use App\Enums\StockMovementType;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * StockMovement records every stock change per product per warehouse (append-only).
 * Type indicates direction: *_in adds stock, *_out subtracts.
 * Tenant isolation is via the related product's company_id.
 *
 * Movements are immutable: no updates or deletes. To reverse, record a new movement.
 */
class StockMovement extends Model
{
    use HasFactory;

    public const SOURCE_POS = 'POS';

    public const SOURCE_API = 'API';

    public const SOURCE_IMPORT = 'IMPORT';

    public const SOURCE_TRANSFER = 'TRANSFER';

    public const SOURCE_ADJUSTMENT = 'ADJUSTMENT';

    public const SOURCE_RETURN = 'RETURN';

    public const SOURCE_PRODUCTION = 'PRODUCTION';

    public const VALID_SOURCES = [
        self::SOURCE_POS,
        self::SOURCE_API,
        self::SOURCE_IMPORT,
        self::SOURCE_TRANSFER,
        self::SOURCE_ADJUSTMENT,
        self::SOURCE_RETURN,
        self::SOURCE_PRODUCTION,
    ];

    protected $fillable = [
        'company_id',
        'uuid',
        'event_id',
        'idempotency_key',
        'source',
        'reason_code',
        'version',
        'product_id',
        'variant_id',
        'warehouse_id',
        'quantity',
        'unit_cost',
        'type',
        'reference_type',
        'reference_id',
        'movement_date',
        'stock_count_id',
        'damage_report_id',
        'batch_id',
        'serial_id',
        'reversal_movement_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'type' => StockMovementType::class,
            'movement_date' => 'datetime',
        ];
    }

    /** reason_code is nullable in DB; enum cast must not run on null. */
    protected function reasonCode(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null || $value === ''
                ? null
                : MovementReasonCode::from($value),
            set: fn ($value) => $value instanceof MovementReasonCode ? $value->value : $value,
        );
    }

    /**
     * Tenant isolation: only movements whose product belongs to the
     * authenticated user's company. Disabled in console.
     * Immutability: movements are append-only (no update/delete).
     */
    protected static function booted(): void
    {
        static::addGlobalScope('company', function (Builder $builder): void {
            if (app()->runningInConsole()) {
                return;
            }

            if (auth()->check()) {
                $builder->whereHas('product', function (Builder $q): void {
                    $q->where('company_id', auth()->user()->company_id);
                });
            }
        });

        static::creating(function (StockMovement $movement): void {
            if (empty($movement->uuid)) {
                $movement->uuid = (string) Str::uuid();
            }
            if (! isset($movement->version) || $movement->version < 1) {
                $movement->version = 1;
            }

            if (! $movement->product_id) {
                return;
            }

            /** @var Product|null $product */
            $product = Product::withoutGlobalScope('company')->find($movement->product_id);

            if ($product && empty($movement->company_id)) {
                $movement->company_id = $product->company_id;
            }

            if (empty($movement->movement_date)) {
                $movement->movement_date = now();
            }

            // Enforce serial tracking: serialized products must move exactly 1 unit per movement (runtime only).
            if ($product && $product->track_serial && ! app()->runningInConsole()) {
                $qty = (float) ($movement->quantity ?? 0);
                if (abs($qty) !== 1.0) {
                    throw new \InvalidArgumentException("Serialized products must move quantity = 1 per movement (product id {$product->id}).");
                }
            }

            // Normalize quantity sign based on movement type (in/out).
            if ($movement->quantity !== null && $movement->type !== null) {
                $qty = (float) $movement->quantity;
                $typeValue = $movement->type instanceof StockMovementType
                    ? $movement->type->value
                    : (string) $movement->type;

                // For out-types, store negative quantities when a positive value is provided.
                if ($qty > 0 && in_array($typeValue, StockMovementType::stockOutValues(), true)) {
                    $movement->quantity = -$qty;
                }
            }
        });

        // Audit integrity: movements are immutable; corrections use new movements
        static::updating(function (): bool {
            return false;
        });

        static::deleting(function (): bool {
            return false;
        });
    }

    /**
     * Route model binding uses uuid for API endpoints.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public static function findByUuid(string $uuid): ?self
    {
        return static::where('uuid', $uuid)->first();
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

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id');
    }

    public function serial(): BelongsTo
    {
        return $this->belongsTo(ProductSerial::class, 'serial_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }

    public function damageReport(): BelongsTo
    {
        return $this->belongsTo(DamageReport::class);
    }

    /** Original movement that this one reverses (when this is a correction/reversal). */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'reversal_movement_id');
    }

    /** Movement that reverses this one (if any). */
    public function reversedBy(): HasOne
    {
        return $this->hasOne(StockMovement::class, 'reversal_movement_id');
    }

    public static function findByIdempotencyKey(string $key): ?self
    {
        return static::withoutGlobalScope('company')->where('idempotency_key', $key)->first();
    }
}
