<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\GymStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Gym extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name', 'slug', 'legal_name', 'base_currency', 'country_code',
        'timezone', 'status', 'trial_ends_at', 'settings',
    ];

    protected function casts(): array
    {
        return [
            'base_currency' => Currency::class,
            'status' => GymStatus::class,
            'trial_ends_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['role', 'status', 'joined_at'])
            ->withTimestamps();
    }

    public function branches(): HasMany
    {
        return $this->hasMany(GymBranch::class);
    }

    public function members(): HasMany
    {
        // Tenant scopes still apply even when traversing from the tenant registry.
        return $this->hasMany(Member::class);
    }

    public function staffProfiles(): HasMany
    {
        return $this->hasMany(StaffProfile::class);
    }

    public function membershipPlans(): HasMany
    {
        return $this->hasMany(MembershipPlan::class);
    }

    public function memberImports(): HasMany
    {
        return $this->hasMany(MemberImport::class);
    }

    public function billingCustomers(): HasMany
    {
        return $this->hasMany(PlatformBillingCustomer::class);
    }

    public function subscriptions(): HasMany
    {
        // Tenant RLS still scopes this relationship even though the gym registry
        // itself is platform-owned.
        return $this->hasMany(GymSubscription::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function classSessions(): HasMany
    {
        return $this->hasMany(ClassSession::class);
    }

    public function classBookings(): HasMany
    {
        return $this->hasMany(ClassBooking::class);
    }

    public function trainerMemberAssignments(): HasMany { return $this->hasMany(TrainerMemberAssignment::class); }
    public function workoutPlans(): HasMany { return $this->hasMany(WorkoutPlan::class); }
    public function workoutSessions(): HasMany { return $this->hasMany(WorkoutSession::class); }
    public function progressMeasurements(): HasMany { return $this->hasMany(MemberProgressMeasurement::class); }
    public function notificationDeliveries(): HasMany { return $this->hasMany(NotificationDelivery::class); }
}
