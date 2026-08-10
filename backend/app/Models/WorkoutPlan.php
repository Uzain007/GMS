<?php

namespace App\Models;

use App\Enums\WorkoutPlanStatus;
use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkoutPlan extends Model
{
    use BelongsToGym, HasUuids;

    protected $fillable = ['member_id', 'trainer_staff_profile_id', 'created_by', 'title', 'goal', 'notes', 'starts_on', 'ends_on', 'status'];

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'ends_on' => 'immutable_date', 'status' => WorkoutPlanStatus::class];
    }

    public function member(): BelongsTo { return $this->belongsTo(Member::class); }
    public function trainer(): BelongsTo { return $this->belongsTo(StaffProfile::class, 'trainer_staff_profile_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function exercises(): HasMany { return $this->hasMany(WorkoutPlanExercise::class)->orderBy('day_number')->orderBy('sort_order'); }
    public function sessions(): HasMany { return $this->hasMany(WorkoutSession::class); }
}
