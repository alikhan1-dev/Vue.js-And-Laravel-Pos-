<?php

namespace App\Enums;

enum SaleType: string
{
    case Sale = 'sale';
    case Quotation = 'quotation';
    case Return = 'return';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
