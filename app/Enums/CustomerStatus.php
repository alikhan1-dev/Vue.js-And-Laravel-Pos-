<?php

namespace App\Enums;

enum CustomerStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Blocked = 'blocked';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
