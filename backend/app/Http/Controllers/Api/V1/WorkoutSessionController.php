<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TrainerAssignmentStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWorkoutSessionRequest;
use App\Http\Resources\WorkoutSessionResource;
use App\Models\Member;
use App\Models\TrainerMemberAssignment;
use App\Models\WorkoutSession;
use App\Services\TrainingService;
use App\Services\TrainingAccessService;
use App\Support\TenantClock;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class WorkoutSessionController extends Controller
{
    public function index(Request $request, TenantContext $tenant, TrainingAccessService $access): AnonymousResourceCollection
    {
        $from = $request->filled('from')
            ? TenantClock::localDate((string) $request->input('from'))->utc()
            : TenantClock::startOfToday()->subDays(90)->utc();
        $to = $request->filled('to')
            ? TenantClock::localDate((string) $request->input('to'))->utc()
            : TenantClock::startOfToday()->endOfDay()->utc();
        if ($to->isBefore($from) || $from->diffInDays($to) > 366) {
            throw ValidationException::withMessages(['to' => ['Workout history must be ordered and no longer than 366 days.']]);
        }
        $query = WorkoutSession::query()->with(['plan', 'member', 'sets.exercise'])
            ->whereBetween('performed_at', [$from, $to])->orderByDesc('performed_at')->orderByDesc('id');
        $role = $request->user()->roleForGym($tenant->id());
        if ($role === UserRole::Member) {
            $member = Member::query()->where('user_id', $request->user()->getKey())->firstOrFail();
            $query->where('member_id', $member->getKey());
        } elseif ($role === UserRole::Trainer) {
            $trainer = $access->trainerForActor($request->user(), null);
            // A current assignment, not a submitted member ID, scopes trainer history.
            $assignedMembers = TrainerMemberAssignment::query()->select('member_id')
                ->where('trainer_staff_profile_id', $trainer->getKey())
                ->where('status', TrainerAssignmentStatus::Active->value)
                ->whereDate('starts_on', '<=', TenantClock::businessDate())
                ->where(fn ($assignment) => $assignment->whereNull('ends_on')->orWhereDate('ends_on', '>=', TenantClock::businessDate()));
            $query->whereIn('member_id', $assignedMembers);
        } elseif ($request->filled('member_id')) {
            $query->where('member_id', $request->input('member_id'));
        }
        return WorkoutSessionResource::collection($query->cursorPaginate(min(max((int) $request->input('per_page', 30), 1), 100)));
    }

    public function store(StoreWorkoutSessionRequest $request, TrainingService $service): WorkoutSessionResource
    {
        return new WorkoutSessionResource($service->logSession($request->validated(), $request->user(), $request));
    }
}
