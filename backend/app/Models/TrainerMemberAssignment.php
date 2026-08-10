<?php

namespace App\Models;

use App\Enums\TrainerAssignmentStatus;
use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainerMemberAssignment extends Model
{
    use BelongsToGym, HasUuids;

    protected $fillable = ['trainer_staff_profile_id', 'member_id', 'assigned_by', 'status', 'starts_on', 'ends_on', 'notes'];

    protected function casts(): array
    {
        return ['status' => TrainerAssignmentStatus::class, 'starts_on' => 'immutable_date', 'ends_on' => 'immutable_date'];
    }

    public function trainer(): BelongsTo { return $this->belongsTo(StaffProfile::class, 'trainer_staff_profile_id'); }
    public function member(): BelongsTo { return $this->belongsTo(Member::class); }
    public function assignedBy(): BelongsTo { return $this->belongsTo(User::class, 'assigned_by'); }
}
