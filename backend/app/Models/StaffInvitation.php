<?php

namespace App\Models;

use App\Enums\InvitationStatus;
use App\Enums\UserRole;
use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffInvitation extends Model
{
    use BelongsToGym, HasFactory, HasUuids;

    protected $fillable = [
        'home_branch_id', 'invited_by', 'email', 'role', 'employee_number',
        'job_title', 'token_hash', 'status', 'expires_at', 'accepted_at', 'metadata',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'status' => InvitationStatus::class,
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function homeBranch(): BelongsTo
    {
        return $this->belongsTo(GymBranch::class, 'home_branch_id');
    }
}
