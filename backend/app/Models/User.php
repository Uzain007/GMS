<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    public const SESSION_AUTH_VERSION_KEY = 'ironcore_auth_version';

    protected $fillable = ['name', 'email', 'password', 'platform_role', 'email_verified_at'];
    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'auth_version' => 'integer',
            'platform_role' => UserRole::class,
        ];
    }

    public function gyms(): BelongsToMany
    {
        return $this->belongsToMany(Gym::class)
            ->withPivot(['role', 'status', 'joined_at'])
            ->withTimestamps();
    }

    public function isSuperAdmin(): bool
    {
        return $this->platform_role === UserRole::SuperAdmin;
    }

    public function roleForGym(string $gymId): ?UserRole
    {
        if ($this->isSuperAdmin()) {
            return UserRole::SuperAdmin;
        }

        // Suspended tenant memberships never grant permissions, even when the
        // user and role rows still exist for audit/history purposes.
        $role = $this->gyms()
            ->wherePivot('status', 'active')
            ->whereKey($gymId)
            ->first()?->pivot?->role;
        return $role ? UserRole::tryFrom($role) : null;
    }
}
