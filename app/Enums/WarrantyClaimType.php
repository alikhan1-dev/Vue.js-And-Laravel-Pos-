<?php

namespace App\Enums;

enum WarrantyClaimType: string
{
    case Repair = 'repair';
    case Replacement = 'replacement';
    case Inspection = 'inspection';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

