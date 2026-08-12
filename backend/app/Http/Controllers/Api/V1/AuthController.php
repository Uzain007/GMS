<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Services\MfaChallengeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request, MfaChallengeService $mfaChallenges): JsonResponse
    {
        $usesBearerToken = $request->boolean('use_bearer_token');
        if (! $usesBearerToken && ! $request->hasSession()) {
            // Reject non-stateful origins before credential lookup so an
            // untrusted caller cannot trigger a session error or probe users.
            return response()->json([
                'message' => 'A stateful browser origin is required for session login.',
            ], 400);
        }

        $email = mb_strtolower((string) $request->string('email'));
        $password = (string) $request->string('password');
        $user = User::query()->where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['email' => ['The supplied credentials are invalid.']]);
        }

        $deviceName = (string) $request->string('device_name', 'native-client');
        if ($user->mfaEnabled()) {
            // Correct primary credentials do not establish authentication for
            // an MFA-enabled identity. Only the short-lived opaque challenge is
            // returned, with no user/tenant data to persist in the browser.
            return response()->json([
                'data' => $mfaChallenges->create($user, $usesBearerToken, $deviceName),
            ], 202)->withHeaders(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
        }

        if ($usesBearerToken) {
            $user->tokens()->where('name', $deviceName)->delete();
            $token = $user->createToken($deviceName, ['app:use'])->plainTextToken;

            return response()->json([
                'data' => [
                    'authentication' => 'bearer',
                    'token' => $token,
                    'token_type' => 'Bearer',
                    'user' => $this->userPayload($user->load('gyms')),
                ],
            ]);
        }

        // Sanctum's stateful middleware starts the server-side session. The
        // identifier is rotated at login to prevent session fixation, while
        // the browser receives only encrypted/HttpOnly cookie state.
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(User::SESSION_AUTH_VERSION_KEY, $user->auth_version);

        return response()->json([
            'data' => [
                'authentication' => 'session',
                'user' => $this->userPayload($user->load('gyms')),
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->userPayload($request->user()->load('gyms'))]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->deleteCurrentPersonalAccessToken()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        // The Sanctum request guard caches the resolved identity for the
        // current application lifecycle. Clear it as well so logout is
        // immediately observable by subsequent requests and long-lived workers.
        Auth::guard('sanctum')->forgetUser();
        Auth::shouldUse('web');

        return response()->json(status: 204);
    }

    private function userPayload($user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'platform_role' => $user->platform_role?->value,
            'gyms' => $user->relationLoaded('gyms')
                ? $user->gyms->map(fn ($gym) => ['id' => $gym->id, 'name' => $gym->name, 'role' => $gym->pivot->role])->values()
                : [],
        ];
    }
}
