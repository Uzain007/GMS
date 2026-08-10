<?php

namespace App\Enums;

enum ClassBookingStatus: string
{
    case Booked = 'booked';
    case Waitlisted = 'waitlisted';
    case Cancelled = 'cancelled';
    case Attended = 'attended';
    case NoShow = 'no_show';
}
