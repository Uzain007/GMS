<?php

namespace App\Enums;

enum SubscriptionCheckoutStatus: string
{
    case Open = 'open';
    case Completed = 'completed';
    case Expired = 'expired';
}
