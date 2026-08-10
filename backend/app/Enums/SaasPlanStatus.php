<?php

namespace App\Enums;

enum SaasPlanStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
