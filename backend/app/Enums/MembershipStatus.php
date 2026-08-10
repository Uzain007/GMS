<?php

namespace App\Enums;

// Membership states are explicit so billing and access jobs never infer intent.
enum MembershipStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Paused = 'paused';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
