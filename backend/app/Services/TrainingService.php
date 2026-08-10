<?php

namespace App\Services;

use App\Enums\TrainerAssignmentStatus;
use App\Enums\StaffStatus;
use App\Enums\UserRole;
use App\Enums\WorkoutPlanStatus;
use App\Models\Member;
use App\Models\StaffProfile;
use App\Models\TrainerMemberAssignment;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Models\WorkoutSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TrainingService
{
    public function __construct(
        private readonly TrainingAccessService $access,
        private readonly NotificationService $notifications,
        private readonly AuditService $audit,
    ) {}

    public function createAssignment(array $data, User $actor, Request $request): TrainerMemberAssignment
    {
        return DB::transaction(function () use ($data, $actor, $request): TrainerMemberAssignment {
            $trainer = StaffProfile::query()->with('user')->lockForUpdate()->findOrFail($data['trainer_staff_profile_id']);
            $member = Member::query()->lockForUpdate()->findOrFail($data['member_id']);
            if ($trainer->status !== StaffStatus::Active || $trainer->user?->roleForGym($trainer->gym_id) !== UserRole::Trainer) {
                throw ValidationException::withMessages(['trainer_staff_profile_id' => ['The selected staff profile is not an active trainer role.']]);
            }
            if ($this->access->hasCurrentAssignment($trainer->getKey(), $member->getKey())) {
                throw ValidationException::withMessages(['member_id' => ['This trainer already has an active assignment to the member.']]);
            }

            $assignment = TrainerMemberAssignment::query()->create([
                ...$data,
                'assigned_by' => $actor->getKey(),
                'status' => TrainerAssignmentStatus::Active,
            ]);
            $this->audit->record('trainer_assignment.created', $assignment, $actor, after: [
                'trainer_staff_profile_id' => $trainer->getKey(), 'member_id' => $member->getKey(),
            ], request: $request);
            return $assignment->load(['trainer.user', 'member']);
        });
    }

    public function endAssignment(string $assignmentId, string $reason, User $actor, Request $request): TrainerMemberAssignment
    {
        return DB::transaction(function () use ($assignmentId, $reason, $actor, $request): TrainerMemberAssignment {
            $assignment = TrainerMemberAssignment::query()->with(['trainer.user', 'member'])
                ->lockForUpdate()->findOrFail($assignmentId);
            if ($assignment->status !== TrainerAssignmentStatus::Active) {
                throw ValidationException::withMessages(['assignment' => ['The trainer assignment has already ended.']]);
            }

            $before = $assignment->toArray();
            // Status closes access immediately. The retained end date and audit
            // trail preserve history without deleting tenant evidence.
            $assignment->update([
                'status' => TrainerAssignmentStatus::Inactive,
                'ends_on' => $assignment->starts_on->isAfter(today()) ? $assignment->starts_on : today(),
            ]);
            $fresh = $assignment->fresh(['trainer.user', 'member']);
            $this->audit->record('trainer_assignment.ended', $fresh, $actor, $before, $fresh->toArray(), $reason, $request);
            return $fresh;
        });
    }

    public function createPlan(array $data, User $actor, Request $request): WorkoutPlan
    {
        $exercises = $data['exercises'];
        unset($data['exercises']);
        $plan = DB::transaction(function () use ($data, $exercises, $actor, $request): WorkoutPlan {
            $member = $this->access->memberForActor($actor, $data['member_id'], true);
            $trainer = $this->access->trainerForActor($actor, $data['trainer_staff_profile_id']);
            if (! $this->access->hasCurrentAssignment($trainer->getKey(), $member->getKey())) {
                throw ValidationException::withMessages(['member_id' => ['Create an active trainer assignment before prescribing a plan.']]);
            }
            $this->validateExerciseOrder($exercises);
            if (($data['status'] ?? WorkoutPlanStatus::Draft->value) === WorkoutPlanStatus::Active->value) {
                $this->assertNoOtherActivePlan($member->getKey());
            }

            $plan = WorkoutPlan::query()->create([
                ...$data,
                'member_id' => $member->getKey(),
                'trainer_staff_profile_id' => $trainer->getKey(),
                'created_by' => $actor->getKey(),
                'status' => $data['status'] ?? WorkoutPlanStatus::Draft,
            ]);
            foreach ($exercises as $exercise) {
                $plan->exercises()->create($exercise);
            }
            $this->audit->record('workout_plan.created', $plan, $actor, after: [
                'member_id' => $member->getKey(), 'trainer_staff_profile_id' => $trainer->getKey(),
                'status' => $plan->status->value, 'exercise_count' => count($exercises),
            ], request: $request);
            return $plan->load(['member', 'trainer.user', 'exercises']);
        });

        if ($plan->status === WorkoutPlanStatus::Active) {
            $this->notifications->queueWorkoutAssigned($plan, $actor);
        }
        return $plan;
    }

    public function updatePlan(string $planId, array $data, User $actor, Request $request): WorkoutPlan
    {
        $activated = false;
        $plan = DB::transaction(function () use ($planId, $data, $actor, $request, &$activated): WorkoutPlan {
            $plan = WorkoutPlan::query()->with(['member', 'trainer.user', 'exercises'])->lockForUpdate()->findOrFail($planId);
            $this->access->assertPlanAccess($actor, $plan, true);
            $before = $plan->toArray();
            $reason = $data['reason'] ?? null;
            unset($data['reason']);

            if (($data['status'] ?? null) === WorkoutPlanStatus::Active->value && $plan->status !== WorkoutPlanStatus::Active) {
                $this->assertNoOtherActivePlan($plan->member_id, $plan->getKey());
                $activated = true;
            }
            if (isset($data['ends_on']) && $data['ends_on'] < $plan->starts_on->toDateString()) {
                throw ValidationException::withMessages(['ends_on' => ['The plan end cannot precede its start.']]);
            }

            $plan->update($data);
            $fresh = $plan->fresh(['member', 'trainer.user', 'exercises']);
            $this->audit->record('workout_plan.updated', $fresh, $actor, $before, $fresh->toArray(), $reason, $request);
            return $fresh;
        });

        if ($activated) {
            $this->notifications->queueWorkoutAssigned($plan, $actor);
        }
        return $plan;
    }

    public function logSession(array $data, User $actor, Request $request): WorkoutSession
    {
        return DB::transaction(function () use ($data, $actor, $request): WorkoutSession {
            $sets = $data['sets'];
            unset($data['sets']);
            $plan = WorkoutPlan::query()->with('exercises')->lockForUpdate()->findOrFail($data['workout_plan_id']);
            $this->access->assertPlanAccess($actor, $plan);
            if ($plan->status !== WorkoutPlanStatus::Active) {
                throw ValidationException::withMessages(['workout_plan_id' => ['Only an active workout plan can receive a completed session.']]);
            }
            $member = $this->access->memberForActor($actor, $data['member_id'] ?? $plan->member_id, true);
            abort_unless($member->getKey() === $plan->member_id, 403, 'The session member must match the plan.');

            $exerciseIds = $plan->exercises->pluck('id')->all();
            $seen = [];
            foreach ($sets as $set) {
                if (! in_array($set['workout_plan_exercise_id'], $exerciseIds, true)) {
                    throw ValidationException::withMessages(['sets' => ['Every set must reference an exercise from this plan.']]);
                }
                $key = $set['workout_plan_exercise_id'].':'.$set['set_number'];
                if (isset($seen[$key])) {
                    throw ValidationException::withMessages(['sets' => ['Exercise set numbers must be unique inside a session.']]);
                }
                $seen[$key] = true;
            }

            $session = WorkoutSession::query()->create([
                ...$data,
                'member_id' => $member->getKey(),
                'logged_by' => $actor->getKey(),
            ]);
            foreach ($sets as $set) {
                $session->sets()->create($set);
            }
            $this->audit->record('workout_session.logged', $session, $actor, after: [
                'workout_plan_id' => $plan->getKey(), 'member_id' => $member->getKey(), 'set_count' => count($sets),
            ], request: $request);
            return $session->load(['plan', 'member', 'sets.exercise']);
        });
    }

    private function assertNoOtherActivePlan(string $memberId, ?string $exceptId = null): void
    {
        $query = WorkoutPlan::query()->where('member_id', $memberId)->where('status', WorkoutPlanStatus::Active->value)->lockForUpdate();
        if ($exceptId) {
            $query->where('id', '!=', $exceptId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['status' => ['This member already has an active workout plan.']]);
        }
    }

    private function validateExerciseOrder(array $exercises): void
    {
        $seen = [];
        foreach ($exercises as $exercise) {
            $key = $exercise['day_number'].':'.$exercise['sort_order'];
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['exercises' => ['Exercise order must be unique within each plan day.']]);
            }
            if (isset($exercise['target_reps_min'], $exercise['target_reps_max']) && $exercise['target_reps_max'] < $exercise['target_reps_min']) {
                throw ValidationException::withMessages(['exercises' => ['Maximum target reps cannot be below minimum target reps.']]);
            }
            $seen[$key] = true;
        }
    }
}
