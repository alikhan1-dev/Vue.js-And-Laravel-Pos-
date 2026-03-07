<?php

namespace App\Models;

use App\Enums\PurchaseStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Purchase order header. Links to supplier, branch, warehouse.
 */
class Purchase extends Model
{
    protected $fillable = [
        'company_id',
        'branch_id',
        'warehouse_id',
        'supplier_id',
        'purchase_number',
        'status',
        'currency_id',
        'exchange_rate',
        'total',
        'tax_total',
        'discount_total',
        'notes',
        'purchase_date',
        'expected_delivery_date',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'purchase_date' => 'date',
            'expected_delivery_date' => 'date',
            'status' => PurchaseStatus::class,
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

        static::creating(function (Purchase $purchase): void {
            if (! $purchase->purchase_number) {
                $year = now()->format('Y');
                $prefix = 'PO-' . $year . '-';
                $lastNumber = Purchase::withoutGlobalScope('company')
                    ->where('company_id', $purchase->company_id)
                    ->whereYear('created_at', $year)
                    ->whereNotNull('purchase_number')
                    ->where('purchase_number', 'like', $prefix . '%')
                    ->orderByDesc('id')
                    ->value('purchase_number');

                $nextSeq = 1;
                if ($lastNumber) {
                    $parts = explode('-', $lastNumber);
                    $seq = (int) end($parts);
                    $nextSeq = $seq + 1;
                }

                $purchase->purchase_number = $prefix . str_pad((string) $nextSeq, 5, '0', STR_PAD_LEFT);
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseLine::class);
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function supplierInvoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class);
    }

    /** Total quantity received per purchase line (cached on purchase_lines.received_quantity). */
    public function getTotalReceivedQuantity(int $purchaseLineId): float
    {
        $line = $this->lines()->where('id', $purchaseLineId)->first();

        return $line ? (float) $line->received_quantity : 0.0;
    }
}
