<?php

namespace App\Enums;

enum PaymentGatewayStatus: string
{
    case Pending = 'pending';
    case Restricted = 'restricted';
    case Active = 'active';
    case Disabled = 'disabled';
}
