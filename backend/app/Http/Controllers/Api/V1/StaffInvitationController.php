<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InvitationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStaffInvitationRequest;
use App\Http\Resources\StaffInvitationResource;
use App\Http\Resources\StaffProfileResource;
use App\Models\Gym;
use App\Models\StaffInvitation;
use App\Services\StaffInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StaffInvitationController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $query = StaffInvitation::query()->latest();
        if (request()->filled('status')) {
            $query->where('status', request('status'));
        } else {
            $query->where('status', InvitationStatus::Pending->value);
        }

        return StaffInvitationResource::collection(
            $query->paginate(min(max((int) request('per_page', 25), 1), 100))
        );
    }

    public function store(
        StoreStaffInvitationRequest $request,
        StaffInvitationService $service,
    ): JsonResponse {
        [$invitation, $plainToken] = $service->create($request->validated(), $request->user(), $request);

        return response()->json([
            'data' => (new StaffInvitationResource($invitation))->resolve($request),
            // Returned exactly once; production notification jobs deliver this secret.
            'meta' => ['acceptance_token' => $plainToken],
        ], 201);
    }

    public function accept(
        Request $request,
        Gym $gym,
        StaffInvitationService $service,
    ): StaffProfileResource {
        $data = $request->validate(['token' => ['required', 'string', 'size:64']]);
        $profile = $service->accept($gym, $request->user(), $data['token'], $request);
        $profile->setAttribute('tenant_role', $request->user()->roleForGym($gym->getKey())?->value);

        return new StaffProfileResource($profile);
    }
}
