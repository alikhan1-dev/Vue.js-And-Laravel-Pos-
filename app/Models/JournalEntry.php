<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Journal entry header. Lines live in journal_entry_lines.
 * reference_type: Sale, Payment, Adjustment, Refund. reference_id links to source document.
 */
class JournalEntry extends Model
{
    public const REFERENCE_TYPE_SALE = 'Sale';
    public const REFERENCE_TYPE_PAYMENT = 'Payment';
    public const REFERENCE_TYPE_ADJUSTMENT = 'Adjustment';

    public const ENTRY_TYPE_SALE_POSTING = 'sale_posting';
    public const ENTRY_TYPE_PAYMENT_RECEIPT = 'payment_receipt';
    public const ENTRY_TYPE_REFUND = 'refund';
    public const ENTRY_TYPE_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'company_id',
        'reference_type',
        'reference_id',
        'entry_type',
        'status',
        'currency_id',
        'posted_at',
        'is_locked',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
            'is_locked' => 'boolean',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }
}
