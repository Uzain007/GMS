<?php

namespace App\Enums;

// Billing intervals are snapshotted onto memberships to preserve contract history.
enum BillingInterval: string
{
    case OneTime = 'one_time';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';
}
