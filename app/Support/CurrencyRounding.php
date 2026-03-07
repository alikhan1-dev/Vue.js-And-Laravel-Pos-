<?php

namespace App\Support;

/**
 * Consistent rounding for multi-currency accounting.
 *
 * Rules:
 * - All monetary amounts rounded to 2 decimal places (banker's rounding).
 * - Exchange differences (conversion gain/loss) tracked separately.
 * - Base currency amounts = transaction amount × exchange_rate, then rounded.
 */
class CurrencyRounding
{
    public static function round(float $amount, int $precision = 2): float
    {
        return round($amount, $precision, PHP_ROUND_HALF_EVEN);
    }

    public static function toBaseCurrency(float $transactionAmount, float $exchangeRate, int $precision = 2): float
    {
        return self::round($transactionAmount * $exchangeRate, $precision);
    }

    /**
     * Calculate exchange difference (gain/loss) between original base amount
     * and recalculated base amount at a different rate.
     */
    public static function exchangeDifference(float $transactionAmount, float $originalRate, float $newRate, int $precision = 2): float
    {
        $original = self::toBaseCurrency($transactionAmount, $originalRate, $precision);
        $revalued = self::toBaseCurrency($transactionAmount, $newRate, $precision);

        return self::round($revalued - $original, $precision);
    }
}
