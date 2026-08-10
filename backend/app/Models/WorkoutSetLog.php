<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkoutSetLog extends Model
{
    use BelongsToGym, HasUuids;

    protected $fillable = ['workout_session_id', 'workout_plan_exercise_id', 'set_number', 'reps', 'load_grams', 'duration_seconds', 'distance_metres', 'rpe'];

    protected function casts(): array
    {
        return ['set_number' => 'integer', 'reps' => 'integer', 'load_grams' => 'integer', 'duration_seconds' => 'integer', 'distance_metres' => 'integer', 'rpe' => 'integer'];
    }

    public function session(): BelongsTo { return $this->belongsTo(WorkoutSession::class, 'workout_session_id'); }
    public function exercise(): BelongsTo { return $this->belongsTo(WorkoutPlanExercise::class, 'workout_plan_exercise_id'); }
}
