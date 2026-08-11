<?php

namespace App\Models;

use App\Enums\MemberStatus;
use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Member extends Model
{
    use BelongsToGym, HasFactory, HasUuids;

    protected $fillable = [
        'home_branch_id', 'user_id', 'member_number', 'first_name', 'last_name',
        'email', 'phone', 'date_of_birth', 'status', 'joined_at', 'archived_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'status' => MemberStatus::class,
            'joined_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function gym(): BelongsTo
    {
        return $this->belongsTo(Gym::class);
    }

    public function homeBranch(): BelongsTo
    {
        return $this->belongsTo(GymBranch::class, 'home_branch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function memberships(): HasMany
    {
        // The membership model independently enforces gym_id for defence-in-depth.
        return $this->hasMany(Membership::class);
    }

    public function accessCredentials(): HasMany
    {
        return $this->hasMany(MemberAccessCredential::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function classBookings(): HasMany
    {
        return $this->hasMany(ClassBooking::class);
    }

    public function trainerAssignments(): HasMany { return $this->hasMany(TrainerMemberAssignment::class); }
    public function workoutPlans(): HasMany { return $this->hasMany(WorkoutPlan::class); }
    public function workoutSessions(): HasMany { return $this->hasMany(WorkoutSession::class); }
    public function progressMeasurements(): HasMany { return $this->hasMany(MemberProgressMeasurement::class); }
    public function notificationPreference(): HasOne { return $this->hasOne(NotificationPreference::class); }
    public function notificationDeliveries(): HasMany { return $this->hasMany(NotificationDelivery::class); }
    public function accountInvitations(): HasMany { return $this->hasMany(MemberAccountInvitation::class); }
}
