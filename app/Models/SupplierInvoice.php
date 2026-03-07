<?php

namespace App\Models;

use App\Enums\SupplierInvoiceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Supplier invoice. Accounting document from supplier.
 */
class SupplierInvoice extends Model
{
    protected $fillable = [
        'company_id',
        'supplier_id',
        'purchase_id',
        'invoice_number',
        'supplier_invoice_number',
        'total',
        'paid_amount',
        'currency_id',
        'exchange_rate',
        'status',
        'invoice_date',
        'due_date',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:8',
            'invoice_date' => 'date',
            'due_date' => 'date',
            'status' => SupplierInvoiceStatus::class,
        ];
    }

    /** Remaining amount due: total − paid_amount. */
    public function getRemainingAmountAttribute(): float
    {
        return max(0, (float) $this->total - (float) $this->paid_amount);
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

        static::creating(function (SupplierInvoice $invoice): void {
            if (! $invoice->invoice_number) {
                $year = now()->format('Y');
                $prefix = 'SI-' . $year . '-';
                $lastNumber = SupplierInvoice::withoutGlobalScope('company')
                    ->where('company_id', $invoice->company_id)
                    ->whereYear('created_at', $year)
                    ->whereNotNull('invoice_number')
                    ->where('invoice_number', 'like', $prefix . '%')
                    ->orderByDesc('id')
                    ->value('invoice_number');

                $nextSeq = 1;
                if ($lastNumber) {
                    $parts = explode('-', $lastNumber);
                    $seq = (int) end($parts);
                    $nextSeq = $seq + 1;
                }

                $invoice->invoice_number = $prefix . str_pad((string) $nextSeq, 5, '0', STR_PAD_LEFT);
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function supplierPayments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }
}
