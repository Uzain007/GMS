<?php

namespace App\Models;

use App\Enums\StaffStatus;
use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StaffProfile extends Model
{
    use BelongsToGym, HasFactory, HasUuids;

    protected $fillable = [
        'user_id', 'home_branch_id', 'employee_number', 'job_title', 'status',
        'hired_at', 'terminated_at', 'permissions',
    ];

    protected function casts(): array
    {
        return [
            'status' => StaffStatus::class,
            'hired_at' => 'date',
            'terminated_at' => 'date',
            'permissions' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function homeBranch(): BelongsTo
    {
        return $this->belongsTo(GymBranch::class, 'home_branch_id');
    }

    public function branches(): BelongsToMany
    {
        // Pivot rows also carry gym_id so branch assignments cannot cross tenants.
        return $this->belongsToMany(GymBranch::class, 'staff_profile_branch', 'staff_profile_id', 'branch_id')
            ->withPivot(['gym_id', 'is_primary'])
            ->withTimestamps();
    }

    public function classSessions(): HasMany
    {
        return $this->hasMany(ClassSession::class, 'trainer_staff_profile_id');
    }

    public function memberAssignments(): HasMany { return $this->hasMany(TrainerMemberAssignment::class, 'trainer_staff_profile_id'); }
    public function workoutPlans(): HasMany { return $this->hasMany(WorkoutPlan::class, 'trainer_staff_profile_id'); }
}
