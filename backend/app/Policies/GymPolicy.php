<?php

namespace App\Policies;

use App\Models\Gym;
use App\Models\User;

class GymPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->gyms()->wherePivot('status', 'active')->exists();
    }

    public function view(User $user, Gym $gym): bool
    {
        return $user->isSuperAdmin() || $user->gyms()
            ->wherePivot('status', 'active')
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
