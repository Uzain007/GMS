<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case GymOwner = 'gym_owner';
    case GymManager = 'gym_manager';
    case Receptionist = 'receptionist';
    case Trainer = 'trainer';
    case Member = 'member';

    public function canManageGym(): bool
    {
        return in_array($this, [self::SuperAdmin, self::GymOwner, self::GymManager], true);
    }

    public function canRecordCash(): bool
    {
        return in_array($this, [self::SuperAdmin, self::GymOwner, self::GymManager, self::Receptionist], true);
    }
}
