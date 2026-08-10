<?php

namespace App\Services;

use App\Models\MemberProgressMeasurement;
use App\Models\User;
use Illuminate\Http\Request;

class ProgressService
{
    public function __construct(private readonly TrainingAccessService $access, private readonly AuditService $audit) {}

    public function record(array $data, User $actor, Request $request): MemberProgressMeasurement
    {
        $member = $this->access->memberForActor($actor, $data['member_id'] ?? null);
        $measurement = MemberProgressMeasurement::query()->create([
            ...$data,
            'member_id' => $member->getKey(),
            'recorded_by' => $actor->getKey(),
        ]);
        // Audit contains metric/value evidence but no unrelated member metadata.
        $this->audit->record('progress_measurement.recorded', $measurement, $actor, after: [
            'member_id' => $member->getKey(), 'metric' => $measurement->metric->value,
            'value_milli' => $measurement->value_milli, 'unit' => $measurement->unit,
        ], request: $request);
        return $measurement->load('member');
    }
}
