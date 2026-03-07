<?php

namespace App\Models;

use App\Enums\SaleAdjustmentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Adjustment/reversal entry for completed (immutable) sales.
 *
 * Instead of editing completed sales, admins create adjustments that post
 * corrective journal entries. Requires approval workflow: pending → approved → posted.
 */
class SaleAdjustment extends Model
{
    protected $fillable = [
        'company_id',
        'sale_id',
        'adjustment_number',
        'type',
        'amount',
        'reason',
        'approved_by',
        'approved_at',
        'status',
        'metadata',
        'journal_entry_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'type' => SaleAdjustmentType::class,
            'metadata' => 'array',
            'approved_at' => 'datetime',
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

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
