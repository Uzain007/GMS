<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case CheckedIn = 'checked_in';
    case CheckedOut = 'checked_out';
}
