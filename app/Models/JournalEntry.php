<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Journal entry header. Lines live in journal_entry_lines.
 * reference_type: Sale, Payment, Adjustment, Refund. reference_id links to source document.
 * reference_number: human-readable ref (e.g. INV-2026-0001, PAY-2026-00005) for audit and ledger reports.
 * journal_entry_number: human-readable JE code (e.g. JE-2026-00001) unique per company for accountants.
 * Soft-deleted only when not posted; posted entries cannot be deleted.
 */
class JournalEntry extends Model
{
    use SoftDeletes;
    public const REFERENCE_TYPE_SALE = 'Sale';
    public const REFERENCE_TYPE_PAYMENT = 'Payment';
    public const REFERENCE_TYPE_ADJUSTMENT = 'Adjustment';

    public const ENTRY_TYPE_SALE_POSTING = 'sale_posting';
    public const ENTRY_TYPE_PAYMENT_RECEIPT = 'payment_receipt';
    public const ENTRY_TYPE_REFUND = 'refund';
    public const ENTRY_TYPE_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'company_id',
        'branch_id',
        'journal_entry_number',
        'reference_type',
        'reference_id',
        'reference_number',
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

        static::creating(function (JournalEntry $entry): void {
            if (! $entry->journal_entry_number) {
                $year = now()->format('Y');
                $prefix = 'JE-' . $year . '-';

                $lastNumber = JournalEntry::withoutGlobalScope('company')
                    ->where('company_id', $entry->company_id)
                    ->whereYear('created_at', $year)
                    ->whereNotNull('journal_entry_number')
                    ->where('journal_entry_number', 'like', $prefix . '%')
                    ->orderByDesc('id')
                    ->value('journal_entry_number');

                $nextSeq = 1;
                if ($lastNumber) {
                    $parts = explode('-', $lastNumber);
                    $seq = (int) end($parts);
                    $nextSeq = $seq + 1;
                }

                $entry->journal_entry_number = $prefix . str_pad((string) $nextSeq, 5, '0', STR_PAD_LEFT);
            }
        });

        static::deleting(function (JournalEntry $entry): bool {
            if ($entry->is_locked || $entry->status === 'posted') {
                return false; // Never allow delete when posted/locked
            }
            return true;
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }
}
