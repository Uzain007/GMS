<?php

namespace App\Enums;

// Branches with business history are deactivated; only completely unused
// non-primary branches can be removed through the audited lifecycle endpoint.
enum BranchStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
