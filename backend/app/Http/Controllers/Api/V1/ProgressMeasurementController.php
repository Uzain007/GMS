<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProgressMeasurementStatus;
use App\Enums\TrainerAssignmentStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\CorrectProgressMeasurementRequest;
use App\Http\Requests\StoreProgressMeasurementRequest;
use App\Http\Requests\VoidProgressMeasurementRequest;
use App\Http\Resources\MemberProgressMeasurementResource;
use App\Models\Member;
use App\Models\MemberProgressMeasurement;
use App\Models\TrainerMemberAssignment;
use App\Services\ProgressService;
use App\Services\TrainingAccessService;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class ProgressMeasurementController extends Controller
{
    public function index(Request $request, TenantContext $tenant, TrainingAccessService $access): AnonymousResourceCollection
    {
        $from = CarbonImmutable::parse((string) $request->input('from', now()->subYear()->startOfDay()->toIso8601String()));
        $to = CarbonImmutable::parse((string) $request->input('to', now()->endOfDay()->toIso8601String()));
        if ($to->isBefore($from) || $from->diffInDays($to) > 732) {
            throw ValidationException::withMessages(['to' => ['Progress history must be ordered and no longer than two years.']]);
        }
        $query = MemberProgressMeasurement::query()->with('member')
            ->whereBetween('measured_at', [$from, $to])->orderByDesc('measured_at')->orderByDesc('id');
        $role = $request->user()->roleForGym($tenant->id());
        // Only gym management can request correction/void history. Member and
        // trainer views always receive the active clinical/business timeline.
        if (! ($role?->canManageGym() ?? false) || ! $request->boolean('include_history')) {
            $query->where('status', ProgressMeasurementStatus::Active->value);
        }
        if ($role === UserRole::Member) {
            $member = Member::query()->where('user_id', $request->user()->getKey())->firstOrFail();
            $query->where('member_id', $member->getKey());
        } elseif ($role === UserRole::Trainer) {
            $trainer = $access->trainerForActor($request->user(), null);
            $assignedMembers = TrainerMemberAssignment::query()->select('member_id')
                ->where('trainer_staff_profile_id', $trainer->getKey())
                ->where('status', TrainerAssignmentStatus::Active->value)
                ->whereDate('starts_on', '<=', today())
                ->where(fn ($assignment) => $assignment->whereNull('ends_on')->orWhereDate('ends_on', '>=', today()));
            $query->whereIn('member_id', $assignedMembers);
        } elseif ($request->filled('member_id')) {
            $query->where('member_id', $request->input('member_id'));
        }
        if ($request->filled('metric')) {
            $query->where('metric', $request->input('metric'));
        }
        return MemberProgressMeasurementResource::collection($query->cursorPaginate(min(max((int) $request->input('per_page', 50), 1), 100)));
    }

    public function store(StoreProgressMeasurementRequest $request, ProgressService $service): MemberProgressMeasurementResource
    {
        return new MemberProgressMeasurementResource($service->record($request->validated(), $request->user(), $request));
    }

    public function update(
        CorrectProgressMeasurementRequest $request,
        string $measurement,
        ProgressService $service,
    ): MemberProgressMeasurementResource {
        return new MemberProgressMeasurementResource(
            $service->correct($measurement, $request->validated(), $request->user(), $request),
        );
    }

    public function destroy(
        VoidProgressMeasurementRequest $request,
        string $measurement,
        ProgressService $service,
    ): MemberProgressMeasurementResource {
        return new MemberProgressMeasurementResource(
            $service->void($measurement, $request->validated()['reason'], $request->user(), $request),
        );
    }
}
