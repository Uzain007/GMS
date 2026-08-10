<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMembershipRequest;
use App\Http\Requests\UpdateMembershipRequest;
use App\Http\Resources\MembershipResource;
use App\Models\Membership;
use App\Services\AuditService;
use App\Services\MembershipService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class MembershipController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $query = Membership::query()->latest();
        foreach (['status', 'member_id', 'plan_id', 'branch_id'] as $filter) {
            if (request()->filled($filter)) {
                $query->where($filter, request($filter));
            }
        }

        return MembershipResource::collection(
            $query->paginate(min(max((int) request('per_page', 25), 1), 100))
        );
    }

    public function store(
        StoreMembershipRequest $request,
        MembershipService $service,
    ): MembershipResource {
        return new MembershipResource(
            $service->create($request->validated(), $request->user(), $request)
        );
    }

    public function show(string $membership): MembershipResource
    {
        return new MembershipResource(Membership::query()->findOrFail($membership));
    }

    public function update(
        UpdateMembershipRequest $request,
        string $membership,
        AuditService $audit,
    ): MembershipResource {
        $model = Membership::query()->findOrFail($membership);
        $before = $model->toArray();
        $data = $request->safe()->except('reason');

        if (($data['status'] ?? null) === MembershipStatus::Cancelled->value) {
            $data['cancelled_at'] = now();
            $data['auto_renew'] = false;
        }

        $fresh = DB::transaction(function () use ($model, $data, $audit, $request, $before): Membership {
            $model->update($data);
            $fresh = $model->fresh();
            $audit->record(
                'membership.updated',
                $fresh,
                $request->user(),
                $before,
                $fresh->toArray(),
                (string) $request->string('reason'),
                $request,
            );
            return $fresh;
        });

        return new MembershipResource($fresh);
    }
}
