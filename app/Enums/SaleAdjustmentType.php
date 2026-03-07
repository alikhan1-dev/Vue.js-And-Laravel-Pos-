<?php

namespace App\Enums;

enum SaleAdjustmentType: string
{
    case PriceCorrection = 'price_correction';
    case QuantityCorrection = 'quantity_correction';
    case DiscountCorrection = 'discount_correction';
    case TaxCorrection = 'tax_correction';
    case Cancellation = 'cancellation';
    case Other = 'other';
}
