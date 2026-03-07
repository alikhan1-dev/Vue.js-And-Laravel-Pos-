<?php

namespace App\Models;

use App\Enums\GoodsReceiptStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Goods receipt header. Tracks actual received stock (supports partial shipments).
 */
class GoodsReceipt extends Model
{
    protected $fillable = [
        'company_id',
        'branch_id',
        'warehouse_id',
        'purchase_id',
        'receipt_number',
        'status',
        'received_at',
        'created_by',
        'received_by',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'status' => GoodsReceiptStatus::class,
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

        static::creating(function (GoodsReceipt $receipt): void {
            if (! $receipt->receipt_number) {
                $year = now()->format('Y');
                $prefix = 'GR-' . $year . '-';
                $lastNumber = GoodsReceipt::withoutGlobalScope('company')
                    ->where('company_id', $receipt->company_id)
                    ->whereYear('created_at', $year)
                    ->whereNotNull('receipt_number')
                    ->where('receipt_number', 'like', $prefix . '%')
                    ->orderByDesc('id')
                    ->value('receipt_number');

                $nextSeq = 1;
                if ($lastNumber) {
                    $parts = explode('-', $lastNumber);
                    $seq = (int) end($parts);
                    $nextSeq = $seq + 1;
                }

                $receipt->receipt_number = $prefix . str_pad((string) $nextSeq, 5, '0', STR_PAD_LEFT);
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

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class, 'goods_receipt_id');
    }
}
