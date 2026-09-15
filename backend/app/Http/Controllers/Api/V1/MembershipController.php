<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMembershipRequest;
use App\Http\Requests\UpdateMembershipRequest;
use App\Http\Resources\MembershipResource;
use App\Models\Membership;
use App\Services\MembershipService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
        MembershipService $service,
    ): MembershipResource {
        $model = Membership::query()->findOrFail($membership);
        return new MembershipResource($service->update($model, $request->validated(), $request->user(), $request));
    }
}
