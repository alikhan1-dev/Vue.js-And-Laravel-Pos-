<?php

namespace App\Enums;

enum WarrantyStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Void = 'void';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

