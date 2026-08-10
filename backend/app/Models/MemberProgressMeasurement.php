<?php

namespace App\Models;

use App\Enums\ProgressMetric;
use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberProgressMeasurement extends Model
{
    use BelongsToGym, HasUuids;

    protected $fillable = ['member_id', 'recorded_by', 'metric', 'value_milli', 'unit', 'measured_at', 'note'];

    protected function casts(): array
    {
        return ['metric' => ProgressMetric::class, 'value_milli' => 'integer', 'measured_at' => 'immutable_datetime'];
    }

    public function member(): BelongsTo { return $this->belongsTo(Member::class); }
    public function recordedBy(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
}
