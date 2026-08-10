<?php

namespace App\Enums;

// Staff profiles remain for audit history after access is suspended or ended.
enum StaffStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Inactive = 'inactive';
}
