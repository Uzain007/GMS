<?php

namespace App\Models;

use App\Enums\BranchStatus;
use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GymBranch extends Model
{
    use BelongsToGym, HasFactory, HasUuids;

    protected $fillable = [
        'name', 'code', 'email', 'phone', 'timezone', 'address', 'status', 'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'address' => 'array',
            'status' => BranchStatus::class,
            'is_primary' => 'boolean',
        ];
    }

    public function gym(): BelongsTo
    {
        return $this->belongsTo(Gym::class);
    }

    public function members(): HasMany
    {
        // Member's global tenant scope adds gym_id in addition to this branch key.
        return $this->hasMany(Member::class, 'home_branch_id');
    }

    public function membershipPlans(): HasMany
    {
        return $this->hasMany(MembershipPlan::class, 'branch_id');
    }

    public function classSessions(): HasMany
    {
        return $this->hasMany(ClassSession::class, 'branch_id');
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class, 'branch_id');
    }
}
