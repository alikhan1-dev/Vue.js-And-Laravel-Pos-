<?php

namespace App\Enums;

enum SalePaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';
    case Refunded = 'refunded';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function fromPaidAndTotal(float $paidAmount, float $total): self
    {
        if ($total <= 0) {
            return self::Paid;
        }
        if ($paidAmount <= 0) {
            return self::Unpaid;
        }
        if ($paidAmount >= $total) {
            return self::Paid;
        }

        return self::Partial;
    }
}
