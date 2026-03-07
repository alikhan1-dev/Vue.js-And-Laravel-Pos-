<?php

namespace App\Enums;

enum DiscountType: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';
    case Promotion = 'promotion';
    case Coupon = 'coupon';
    case Manual = 'manual';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
