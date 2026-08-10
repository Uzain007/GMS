<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMembershipPlanRequest;
use App\Http\Requests\UpdateMembershipPlanRequest;
use App\Http\Resources\MembershipPlanResource;
use App\Models\MembershipPlan;
use App\Services\AuditService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class MembershipPlanController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $query = MembershipPlan::query()->orderBy('name');
        if (request()->filled('status')) {
            $query->where('status', request('status'));
        }
        if (request()->filled('branch_id')) {
            $query->where(fn ($builder) => $builder
                ->whereNull('branch_id')
                ->orWhere('branch_id', request('branch_id')));
        }

        return MembershipPlanResource::collection(
            $query->paginate(min(max((int) request('per_page', 25), 1), 100))
        );
    }

    public function store(
        StoreMembershipPlanRequest $request,
        AuditService $audit,
    ): MembershipPlanResource {
        $plan = DB::transaction(function () use ($request, $audit): MembershipPlan {
            $plan = MembershipPlan::query()->create($request->validated());
            $audit->record('membership_plan.created', $plan, $request->user(), after: $plan->toArray(), request: $request);
            return $plan;
        });
        return new MembershipPlanResource($plan);
    }

    public function show(string $plan): MembershipPlanResource
    {
        return new MembershipPlanResource(MembershipPlan::query()->findOrFail($plan));
    }

    public function update(
        UpdateMembershipPlanRequest $request,
        string $plan,
        AuditService $audit,
    ): MembershipPlanResource {
        $model = MembershipPlan::query()->findOrFail($plan);
        $before = $model->toArray();
        $fresh = DB::transaction(function () use ($request, $audit, $model, $before): MembershipPlan {
            $model->update($request->safe()->except('reason'));
            $fresh = $model->fresh();
            // Price and terms changes cannot commit without their audit evidence.
            $audit->record(
                'membership_plan.updated',
                $fresh,
                $request->user(),
                $before,
                $fresh->toArray(),
                (string) $request->string('reason'),
                $request,
            );
            return $fresh;
        });
        return new MembershipPlanResource($fresh);
    }
}
