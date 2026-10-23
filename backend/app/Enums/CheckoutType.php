<?php

namespace App\Enums;

enum CheckoutType: string
{
    case Manual = 'manual';
    case Automatic = 'automatic';
}
