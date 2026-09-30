<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InvitationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStaffInvitationRequest;
use App\Http\Resources\StaffInvitationResource;
use App\Models\Gym;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Services\MfaChallengeService;
use App\Services\StaffInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

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

    public function resend(
        Request $request,
        StaffInvitation $invitation,
        StaffInvitationService $service,
    ): JsonResponse {
        $data = $request->validate(['expires_in_days' => ['sometimes', 'integer', 'between:1,30']]);
        [$resent, $plainToken] = $service->resend(
            $invitation,
            $request->user(),
            $request,
            (int) ($data['expires_in_days'] ?? 7),
        );

        return response()->json([
            'data' => (new StaffInvitationResource($resent))->resolve($request),
            'meta' => ['acceptance_token' => $plainToken],
        ]);
    }

    public function revoke(
        Request $request,
        StaffInvitation $invitation,
        StaffInvitationService $service,
    ): StaffInvitationResource {
        return new StaffInvitationResource($service->revoke($invitation, $request->user(), $request));
    }

    public function preview(
        Request $request,
        Gym $gym,
        StaffInvitationService $service,
    ): JsonResponse {
        $data = $request->validate(['token' => ['required', 'string', 'size:64']]);

        return response()->json(['data' => $service->preview($gym, $data['token'])]);
    }

    public function accept(
        Request $request,
        Gym $gym,
        StaffInvitationService $service,
        MfaChallengeService $mfaChallenges,
    ): JsonResponse {
        if (! $request->hasSession()) {
            return response()->json([
                'message' => 'A stateful browser origin is required for account activation.',
            ], 400);
        }

        $data = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'password' => ['nullable', 'string', 'min:12', 'max:255', 'confirmed'],
        ]);
        $user = $service->accept($gym, $data['token'], $data['password'] ?? null, $request);

        if ($user->mfaEnabled()) {
            return response()->json([
                'data' => $mfaChallenges->create($user),
                'message' => 'The staff account was activated. Enter your authentication code to continue.',
            ], 202)->withHeaders(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(User::SESSION_AUTH_VERSION_KEY, $user->auth_version);
        $request->session()->put(User::SESSION_STARTED_AT_KEY, now()->getTimestamp());

        return response()->json([
            'data' => ['authentication' => 'session'],
            'message' => 'Your staff account is active.',
        ]);
    }
}
