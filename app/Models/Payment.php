<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Payment record. May link to a sale (nullable for standalone/invoice payments).
 * Total amount = sum of payment_lines. Tenant-scoped by company_id.
 */
class Payment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'sale_id',
        'customer_id',
        'sale_number',
        'company_id',
        'branch_id',
        'warehouse_id',
        'amount',
        'currency_id',
        'exchange_rate',
        'rate_source',
        'primary_payment_method_id',
        'payment_date',
        'payment_number',
        'notes',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'date',
            'status' => PaymentStatus::class,
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

        static::creating(function (Payment $payment): void {
            if (! $payment->payment_number) {
                $year = now()->format('Y');
                $prefix = 'PAY-' . $year . '-';
                $lastNumber = Payment::withoutGlobalScope('company')
                    ->where('company_id', $payment->company_id)
                    ->whereYear('created_at', $year)
                    ->whereNotNull('payment_number')
                    ->where('payment_number', 'like', $prefix . '%')
                    ->orderByDesc('id')
                    ->value('payment_number');

                $nextSeq = 1;
                if ($lastNumber) {
                    $parts = explode('-', $lastNumber);
                    $seq = (int) end($parts);
                    $nextSeq = $seq + 1;
                }

                $payment->payment_number = $prefix . str_pad((string) $nextSeq, 5, '0', STR_PAD_LEFT);
            }
        });

        static::deleting(function (Payment $payment): bool {
            if ($payment->status === PaymentStatus::Completed) {
                // Prevent deleting completed payments; caller should cancel or refund instead.
                return false;
            }

            return true;
        });
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function primaryPaymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'primary_payment_method_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PaymentLine::class);
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'reference_id')
            ->where('reference_type', JournalEntry::REFERENCE_TYPE_PAYMENT);
    }
}
