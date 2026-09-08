<?php

namespace App\Enums;

enum GymStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case PastDue = 'past_due';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    public function allowsLogin(): bool
    {
        return ! in_array($this, [self::Suspended, self::Cancelled], true);
    }
}
