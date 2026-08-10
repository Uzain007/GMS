<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TrainerAssignmentStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWorkoutPlanRequest;
use App\Http\Requests\UpdateWorkoutPlanRequest;
use App\Http\Resources\WorkoutPlanResource;
use App\Models\Member;
use App\Models\TrainerMemberAssignment;
use App\Models\WorkoutPlan;
use App\Services\TrainingAccessService;
use App\Services\TrainingService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WorkoutPlanController extends Controller
{
    public function index(Request $request, TenantContext $tenant, TrainingAccessService $access): AnonymousResourceCollection
    {
        $query = WorkoutPlan::query()->with(['member', 'trainer.user', 'exercises'])->orderByDesc('starts_on')->orderByDesc('id');
        $role = $request->user()->roleForGym($tenant->id());
        if ($role === UserRole::Member) {
            $member = Member::query()->where('user_id', $request->user()->getKey())->firstOrFail();
            $query->where('member_id', $member->getKey());
        } elseif ($role === UserRole::Trainer) {
            $trainer = $access->trainerForActor($request->user(), null);
            // Trainer lists are constrained by the same current assignment
            // boundary as individual reads; expired relationships reveal none.
            $assignedMembers = TrainerMemberAssignment::query()->select('member_id')
                ->where('trainer_staff_profile_id', $trainer->getKey())
                ->where('status', TrainerAssignmentStatus::Active->value)
                ->whereDate('starts_on', '<=', today())
                ->where(fn ($assignment) => $assignment->whereNull('ends_on')->orWhereDate('ends_on', '>=', today()));
            $query->where('trainer_staff_profile_id', $trainer->getKey())
                ->whereIn('member_id', $assignedMembers);
        } else {
            foreach (['member_id', 'trainer_staff_profile_id', 'status'] as $filter) {
                if ($request->filled($filter)) {
                    $query->where($filter, $request->input($filter));
                }
            }
        }
        return WorkoutPlanResource::collection($query->cursorPaginate(min(max((int) $request->input('per_page', 30), 1), 100)));
    }

    public function show(Request $request, string $plan, TrainingAccessService $access): WorkoutPlanResource
    {
        $model = WorkoutPlan::query()->with(['member', 'trainer.user', 'exercises'])->findOrFail($plan);
        $access->assertPlanAccess($request->user(), $model);
        return new WorkoutPlanResource($model);
    }

    public function store(StoreWorkoutPlanRequest $request, TrainingService $service): WorkoutPlanResource
    {
        return new WorkoutPlanResource($service->createPlan($request->validated(), $request->user(), $request));
    }

    public function update(UpdateWorkoutPlanRequest $request, string $plan, TrainingService $service): WorkoutPlanResource
    {
        return new WorkoutPlanResource($service->updatePlan($plan, $request->validated(), $request->user(), $request));
    }
}
