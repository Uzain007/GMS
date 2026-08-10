<?php

namespace App\Enums;

enum AttendanceMethod: string
{
    case Qr = 'qr';
    case MemberCode = 'member_code';
    case Manual = 'manual';
}
