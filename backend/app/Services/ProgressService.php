<?php

namespace App\Services;

use App\Enums\ProgressMeasurementStatus;
use App\Models\MemberProgressMeasurement;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
            'status' => ProgressMeasurementStatus::Active->value,
        ]);
        // Audit contains metric/value evidence but no unrelated member metadata.
        $this->audit->record('progress_measurement.recorded', $measurement, $actor, after: [
            'member_id' => $member->getKey(), 'metric' => $measurement->metric->value,
            'value_milli' => $measurement->value_milli, 'unit' => $measurement->unit,
        ], request: $request);
        return $measurement->load('member');
    }

    public function correct(string $measurementId, array $data, User $actor, Request $request): MemberProgressMeasurement
    {
        return DB::transaction(function () use ($measurementId, $data, $actor, $request): MemberProgressMeasurement {
            // The tenant global scope and database RLS both fail closed before
            // the row lock is acquired, preventing cross-gym correction races.
            $measurement = MemberProgressMeasurement::query()->lockForUpdate()->findOrFail($measurementId);
            $this->ensureActive($measurement);
            $before = $this->evidence($measurement);

            $replacement = MemberProgressMeasurement::query()->create([
                ...Arr::except($data, ['reason']),
                'member_id' => $measurement->member_id,
                'recorded_by' => $actor->getKey(),
                'status' => ProgressMeasurementStatus::Active->value,
                'replaces_measurement_id' => $measurement->getKey(),
            ]);
            $measurement->update(['status' => ProgressMeasurementStatus::Corrected->value]);

            $this->audit->record(
                'progress_measurement.corrected',
                $replacement,
                $actor,
                before: $before,
                after: $this->evidence($replacement),
                reason: $data['reason'],
                request: $request,
            );

            return $replacement->load('member');
        });
    }

    public function void(string $measurementId, string $reason, User $actor, Request $request): MemberProgressMeasurement
    {
        return DB::transaction(function () use ($measurementId, $reason, $actor, $request): MemberProgressMeasurement {
            // Voiding retains the immutable values while removing the row from
            // live charts; the audit reason records why management did so.
            $measurement = MemberProgressMeasurement::query()->lockForUpdate()->findOrFail($measurementId);
            $this->ensureActive($measurement);
            $before = $this->evidence($measurement);
            $measurement->update([
                'status' => ProgressMeasurementStatus::Voided->value,
                'voided_by' => $actor->getKey(),
                'voided_at' => now(),
            ]);

            $this->audit->record(
                'progress_measurement.voided',
                $measurement,
                $actor,
                before: $before,
                after: $this->evidence($measurement),
                reason: $reason,
                request: $request,
            );

            return $measurement->load('member');
        });
    }

    private function ensureActive(MemberProgressMeasurement $measurement): void
    {
        if ($measurement->status !== ProgressMeasurementStatus::Active) {
            throw ValidationException::withMessages([
                'measurement' => ['Only an active measurement can be edited or deleted.'],
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function evidence(MemberProgressMeasurement $measurement): array
    {
        return [
            'id' => $measurement->getKey(),
            'member_id' => $measurement->member_id,
            'metric' => $measurement->metric->value,
            'value_milli' => $measurement->value_milli,
            'unit' => $measurement->unit,
            'measured_at' => $measurement->measured_at?->toIso8601String(),
            'note' => $measurement->note,
            'status' => $measurement->status->value,
            'replaces_measurement_id' => $measurement->replaces_measurement_id,
            'voided_at' => $measurement->voided_at?->toIso8601String(),
        ];
    }
}
