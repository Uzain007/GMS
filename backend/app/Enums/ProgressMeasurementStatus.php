<?php

namespace App\Enums;

enum ProgressMeasurementStatus: string
{
    case Active = 'active';
    case Corrected = 'corrected';
    case Voided = 'voided';
}
