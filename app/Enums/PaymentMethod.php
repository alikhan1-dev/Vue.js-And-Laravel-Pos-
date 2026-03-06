<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';
    case Cheque = 'cheque';
    case BankTransfer = 'bank_transfer';
    case Wallet = 'wallet';
    case CreditNote = 'credit_note';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
