<?php

namespace App\Enums;

enum SaasInvoiceStatus: string
{
    case Draft = 'draft';
    case Upcoming = 'upcoming';
    case Due = 'due';
    case PastDue = 'past_due';
    case Open = 'open';
    case Paid = 'paid';
    case Void = 'void';
    case Cancelled = 'cancelled';
    case Uncollectible = 'uncollectible';
}
