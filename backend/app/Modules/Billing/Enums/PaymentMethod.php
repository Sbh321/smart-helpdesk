<?php

declare(strict_types=1);

namespace App\Modules\Billing\Enums;

enum PaymentMethod: string
{
    case BankTransfer = 'bank_transfer';
    case Wallet = 'wallet';
    case Cash = 'cash';
    case Other = 'other';
}
