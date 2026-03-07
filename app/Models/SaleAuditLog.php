<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit log for sale lifecycle: created, converted_to_sale, return_created, status_changed.
 * event is enforced as ENUM at DB level (MySQL). PHP constants are source of truth.
 *
 * metadata JSON schema (standardized via SaleAuditMetadata):
 * - lines_count: int (created)
 * - stock_movement_ids: int[] (created, converted_to_sale, return_created)
 * - return_for_sale_id: int (return_created)
 * - lines: array<{ product_id: int, quantity: float, stock_movement_id: int|null }> for line-level traceability
 * created_by links to users.id for user info on every event.
 */
class SaleAuditLog extends Model
{
    public const EVENT_CREATED = 'created';

    public const EVENT_CONVERTED_TO_SALE = 'converted_to_sale';

    public const EVENT_RETURN_CREATED = 'return_created';

    public const EVENT_STATUS_CHANGED = 'status_changed';

    public const EVENT_ADJUSTMENT_CREATED = 'adjustment_created';

    public const EVENT_ADJUSTMENT_APPROVED = 'adjustment_approved';

    protected $table = 'sale_audit_log';

    public $timestamps = false;

    protected $fillable = [
        'sale_id',
        'event',
        'from_status',
        'to_status',
        'from_type',
        'to_type',
        'metadata',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
