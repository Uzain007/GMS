<?php

namespace App\Enums;

// Lifecycle states are indexed within each gym for million-member list queries.
enum MemberStatus: string
{
    case Lead = 'lead';
    case Active = 'active';
    case Paused = 'paused';
    case Cancelled = 'cancelled';
    case Archived = 'archived';
}
