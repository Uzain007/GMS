<?php

namespace App\Enums;

enum ProgressMetric: string
{
    case BodyWeight = 'body_weight';
    case BodyFat = 'body_fat';
    case Waist = 'waist';
    case Chest = 'chest';
    case Hips = 'hips';
    case Biceps = 'biceps';
    case Thigh = 'thigh';
    case Custom = 'custom';
}
