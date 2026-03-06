<?php

namespace App\Observers;

use App\DataTransferObjects\SaleAuditMetadata;
use App\Models\Sale;
use App\Models\SaleAuditLog;
use Illuminate\Support\Facades\Auth;

/**
 * Logs status_change and type_change events for compliance.
 * created / converted_to_sale / return_created are logged from SaleService with full metadata.
 */
class SaleObserver
{
    public function updated(Sale $sale): void
    {
        if (! $sale->wasChanged(['status', 'type'])) {
            return;
        }

        $original = $sale->getOriginal();
        $fromStatus = $original['status'] ?? null;
        $toStatus = $sale->status?->value ?? $sale->getRawOriginal('status');
        $fromType = $original['type'] ?? null;
        $toType = $sale->type?->value ?? $sale->getRawOriginal('type');

        SaleAuditLog::create([
            'sale_id' => $sale->id,
            'event' => SaleAuditLog::EVENT_STATUS_CHANGED,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'from_type' => $fromType,
            'to_type' => $toType,
            'metadata' => SaleAuditMetadata::forStatusChanged(),
            'created_by' => Auth::id(),
        ]);
    }
}
