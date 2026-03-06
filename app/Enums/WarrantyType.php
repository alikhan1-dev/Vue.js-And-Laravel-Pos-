<?php

namespace App\Enums;

enum WarrantyType: string
{
    case Manufacturer = 'manufacturer';
    case Seller = 'seller';
    case Extended = 'extended';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

