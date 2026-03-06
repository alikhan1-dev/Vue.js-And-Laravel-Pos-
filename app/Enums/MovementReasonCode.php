<?php

namespace App\Enums;

enum MovementReasonCode: string
{
    case STOCK_COUNT = 'stock_count';
    case DAMAGE = 'damage';
    case EXPIRED = 'expired';
    case THEFT = 'theft';
    case MANUAL_ADJUSTMENT = 'manual_adjustment';
    case TRANSFER = 'transfer';
    case SALE = 'sale';
    case PURCHASE = 'purchase';
    case RETURN = 'return';
    case PRODUCTION = 'production';
    case INITIAL = 'initial';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
