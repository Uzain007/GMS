<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case OnlineCard = 'online_card';
    case Other = 'other';

    public function isOnline(): bool
    {
        return $this === self::OnlineCard;
    }
}
