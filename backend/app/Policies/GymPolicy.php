<?php

namespace App\Policies;

use App\Models\Gym;
use App\Models\User;

class GymPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->gyms()->wherePivot('status', 'active')
            ->whereNotIn('gyms.status', ['suspended', 'cancelled'])->exists();
    }

    public function view(User $user, Gym $gym): bool
    {
        return $user->isSuperAdmin() || $user->gyms()
            ->wherePivot('status', 'active')
            ->whereNotIn('gyms.status', ['suspended', 'cancelled'])
            ->whereKey($gym->getKey())
            ->exists();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, Gym $gym): bool
    {
        return $user->roleForGym($gym->getKey())?->canManageGym() === true;
    }
}
