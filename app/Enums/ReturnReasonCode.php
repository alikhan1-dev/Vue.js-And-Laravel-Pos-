<?php

namespace App\Enums;

enum ReturnReasonCode: string
{
    case Damaged = 'damaged';
    case CustomerReturn = 'customer_return';
    case WrongItem = 'wrong_item';
    case Warranty = 'warranty';
    case Fraud = 'fraud';
    case Other = 'other';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
