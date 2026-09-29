<?php

namespace App\Models;

use App\Enums\TrainerAssignmentStatus;
use App\Models\Concerns\BelongsToGym;
use App\Support\TenantClock;
use Illuminate\Database\Eloquent\Builder;
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

    public function scopeCurrent(Builder $query, ?string $businessDate = null): Builder
    {
        $date = $businessDate ?? TenantClock::businessDate();

        return $query
            ->where('status', TrainerAssignmentStatus::Active->value)
            ->whereDate('starts_on', '<=', $date)
            ->where(fn (Builder $assignment) => $assignment
                ->whereNull('ends_on')
                ->orWhereDate('ends_on', '>=', $date));
    }

    public function effectiveStatus(?string $businessDate = null): string
    {
        if ($this->status !== TrainerAssignmentStatus::Active) {
            return TrainerAssignmentStatus::Inactive->value;
        }

        $date = $businessDate ?? TenantClock::businessDate();
        if ($this->starts_on->toDateString() > $date) {
            return 'scheduled';
        }
        if ($this->ends_on && $this->ends_on->toDateString() < $date) {
            return 'expired';
        }

        return TrainerAssignmentStatus::Active->value;
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'trainer_staff_profile_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
