<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkoutPlanExercise extends Model
{
    use BelongsToGym, HasUuids;

    protected $fillable = ['workout_plan_id', 'name', 'instructions', 'day_number', 'sort_order', 'target_sets', 'target_reps_min', 'target_reps_max', 'target_load_grams', 'target_duration_seconds', 'rest_seconds'];

    protected function casts(): array
    {
        return ['day_number' => 'integer', 'sort_order' => 'integer', 'target_sets' => 'integer', 'target_reps_min' => 'integer', 'target_reps_max' => 'integer', 'target_load_grams' => 'integer', 'target_duration_seconds' => 'integer', 'rest_seconds' => 'integer'];
    }

    public function plan(): BelongsTo { return $this->belongsTo(WorkoutPlan::class, 'workout_plan_id'); }
    public function setLogs(): HasMany { return $this->hasMany(WorkoutSetLog::class); }
}
