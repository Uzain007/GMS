<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkoutSession extends Model
{
    use BelongsToGym, HasUuids;

    protected $fillable = ['workout_plan_id', 'member_id', 'logged_by', 'performed_at', 'duration_seconds', 'notes'];

    protected function casts(): array
    {
        return ['performed_at' => 'immutable_datetime', 'duration_seconds' => 'integer'];
    }

    public function plan(): BelongsTo { return $this->belongsTo(WorkoutPlan::class, 'workout_plan_id'); }
    public function member(): BelongsTo { return $this->belongsTo(Member::class); }
    public function loggedBy(): BelongsTo { return $this->belongsTo(User::class, 'logged_by'); }
    public function sets(): HasMany { return $this->hasMany(WorkoutSetLog::class); }
}
