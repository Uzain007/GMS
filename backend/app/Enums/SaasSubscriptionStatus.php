<?php

namespace App\Enums;

enum SaasSubscriptionStatus: string
{
    case Incomplete = 'incomplete';
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Unpaid = 'unpaid';
    case Paused = 'paused';
    case Cancelled = 'cancelled';
    case IncompleteExpired = 'incomplete_expired';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Cancelled, self::IncompleteExpired], true);
    }
}
