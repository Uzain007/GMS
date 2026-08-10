<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TrainerAssignmentStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\EndTrainerAssignmentRequest;
use App\Http\Requests\StoreTrainerAssignmentRequest;
use App\Http\Resources\TrainerMemberAssignmentResource;
use App\Models\Member;
use App\Models\TrainerMemberAssignment;
use App\Services\TrainingAccessService;
use App\Services\TrainingService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TrainerAssignmentController extends Controller
{
    public function index(Request $request, TenantContext $tenant, TrainingAccessService $access): AnonymousResourceCollection
    {
        $query = TrainerMemberAssignment::query()->with(['trainer.user', 'member'])->orderByDesc('starts_on')->orderByDesc('id');
        $role = $request->user()->roleForGym($tenant->id());
        if ($role === UserRole::Member) {
            $member = Member::query()->where('user_id', $request->user()->getKey())->firstOrFail();
            $query->where('member_id', $member->getKey());
        } elseif ($role === UserRole::Trainer) {
            $trainer = $access->trainerForActor($request->user(), null);
            // Expired assignments must stop exposing member identity in list
            // responses as soon as the server-authoritative boundary closes.
            $query->where('trainer_staff_profile_id', $trainer->getKey())
                ->where('status', TrainerAssignmentStatus::Active->value)
                ->whereDate('starts_on', '<=', today())
                ->where(fn ($assignment) => $assignment->whereNull('ends_on')->orWhereDate('ends_on', '>=', today()));
        } else {
            foreach (['member_id', 'trainer_staff_profile_id', 'status'] as $filter) {
                if ($request->filled($filter)) {
                    $query->where($filter, $request->input($filter));
                }
            }
        }
        return TrainerMemberAssignmentResource::collection($query->cursorPaginate(min(max((int) $request->input('per_page', 50), 1), 100)));
    }

    public function store(StoreTrainerAssignmentRequest $request, TrainingService $service): TrainerMemberAssignmentResource
    {
        return new TrainerMemberAssignmentResource($service->createAssignment($request->validated(), $request->user(), $request));
    }

    public function end(EndTrainerAssignmentRequest $request, string $assignment, TrainingService $service): TrainerMemberAssignmentResource
    {
        return new TrainerMemberAssignmentResource($service->endAssignment(
            $assignment,
            $request->validated('reason'),
            $request->user(),
            $request,
        ));
    }
}
