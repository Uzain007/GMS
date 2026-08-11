<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMemberAccountInvitationRequest;
use App\Http\Resources\MemberAccountInvitationResource;
use App\Models\Gym;
use App\Models\User;
use App\Models\MemberAccountInvitation;
use App\Services\MemberAccountInvitationService;
use App\Services\MfaChallengeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

class MemberAccountInvitationController extends Controller
{
    public function index(string $member): AnonymousResourceCollection
    {
        $query = MemberAccountInvitation::query()
            ->where('member_id', $member)
            ->latest();

        if (request()->filled('status')) {
            $query->where('status', request('status'));
        }

        return MemberAccountInvitationResource::collection(
            $query->paginate(min(max((int) request('per_page', 25), 1), 100))
        );
    }

    public function store(
        StoreMemberAccountInvitationRequest $request,
        string $member,
        MemberAccountInvitationService $service,
    ): JsonResponse {
        [$invitation, $plainToken] = $service->create(
            $member,
            (int) $request->validated('expires_in_hours', 48),
            $request->user(),
            $request,
        );

        return response()->json([
            'data' => (new MemberAccountInvitationResource($invitation))->resolve($request),
            'meta' => ['activation_token' => $plainToken],
        ], 201);
    }

    public function preview(
        Request $request,
        Gym $gym,
        MemberAccountInvitationService $service,
        MfaChallengeService $mfaChallenges,
    ): JsonResponse {
        $data = $request->validate(['token' => ['required', 'string', 'size:64']]);

        return response()->json(['data' => $service->preview($gym, $data['token'])]);
    }

    public function accept(
        Request $request,
        Gym $gym,
        MemberAccountInvitationService $service,
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
            // The one-time member invitation may link an existing account, but
            // it never bypasses that identity's already-confirmed second factor.
            return response()->json([
                'data' => $mfaChallenges->create($user),
                'message' => 'The member account was linked. Enter your authentication code to continue.',
            ], 202)->withHeaders(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(User::SESSION_AUTH_VERSION_KEY, $user->auth_version);

        return response()->json([
            'data' => [
                'authentication' => 'session',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'platform_role' => $user->platform_role?->value,
                    // Activation establishes this tenant assignment. A later
                    // authenticated /auth/me request can discover other gyms.
                    'gyms' => [[
                        'id' => $gym->id,
                        'name' => $gym->name,
                        'role' => 'member',
                    ]],
                ],
            ],
        ]);
    }
}
