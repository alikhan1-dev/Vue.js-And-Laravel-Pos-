<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cash movement within a POS session: pay_in, pay_out, sale_cash, refund_cash,
 * float_adjustment, drawer_open.
 */
class PosCashMovement extends Model
{
    public const TYPE_PAY_IN = 'pay_in';
    public const TYPE_PAY_OUT = 'pay_out';
    public const TYPE_SALE_CASH = 'sale_cash';
    public const TYPE_REFUND_CASH = 'refund_cash';
    public const TYPE_FLOAT_ADJUSTMENT = 'float_adjustment';
    public const TYPE_DRAWER_OPEN = 'drawer_open';

    protected $fillable = [
        'company_id',
        'pos_session_id',
        'type',
        'amount',
        'reason',
        'reference',
        'payment_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
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

    public function posSession(): BelongsTo
    {
        return $this->belongsTo(PosSession::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
