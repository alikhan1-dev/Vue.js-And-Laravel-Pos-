<?php

namespace App\Enums;

enum SupplierInvoiceStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
