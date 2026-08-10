<?php

namespace App\Enums;

enum PaymentProvider: string
{
    case Manual = 'manual';
    case Stripe = 'stripe';
}
