<?php

namespace App\Enums;

// Branches are deactivated instead of deleted so historical memberships retain context.
enum BranchStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
