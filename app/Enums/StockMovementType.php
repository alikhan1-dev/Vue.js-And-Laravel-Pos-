<?php

namespace App\Enums;

enum StockMovementType: string
{
    case PurchaseIn = 'purchase_in';
    case SaleOut = 'sale_out';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case ReturnIn = 'return_in';
    case ReturnOut = 'return_out';
    case WarrantyReplacementOut = 'warranty_replacement_out';
    case ProductionIn = 'production_in';
    case ProductionOut = 'production_out';
    case DamageOut = 'damage_out';
    case InitialStock = 'initial_stock';

    /** Types that add stock to a warehouse. */
    public static function stockInValues(): array
    {
        return [
            self::PurchaseIn->value,
            self::TransferIn->value,
            self::AdjustmentIn->value,
            self::ReturnIn->value,
            self::ProductionIn->value,
            self::InitialStock->value,
        ];
    }

    /** Types that reduce stock from a warehouse. */
    public static function stockOutValues(): array
    {
        return [
            self::SaleOut->value,
            self::TransferOut->value,
            self::AdjustmentOut->value,
            self::ReturnOut->value,
            self::WarrantyReplacementOut->value,
            self::ProductionOut->value,
            self::DamageOut->value,
        ];
    }

    /** All valid type values for validation. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
