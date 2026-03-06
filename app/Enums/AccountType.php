<?php

namespace App\Enums;

enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';
    case ContraIncome = 'contra_income';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
