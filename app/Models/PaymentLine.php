<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Single payment line: one method (cash, card, etc.) with amount and account.
 * reference stores card transaction ID, cheque number, or bank reference.
 */
class PaymentLine extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'payment_id',
        'payment_method_id',
        'account_id',
        'amount',
        'reference',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
