<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\BeginMfaSetupRequest;
use App\Http\Requests\ConfirmMfaSetupRequest;
use App\Http\Requests\DisableMfaRequest;
use App\Http\Requests\RegenerateMfaRecoveryCodesRequest;
use App\Http\Requests\VerifyMfaChallengeRequest;
use App\Models\User;
use App\Models\UserMfaRecoveryCode;
use App\Services\AuditService;
use App\Services\MfaChallengeService;
use App\Services\MfaService;
use App\Services\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class MfaController extends Controller
{
    public function status(Request $request, MfaService $mfa): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => [
            'enabled' => $user->mfaEnabled(),
            'setup_pending' => $user->mfa_secret !== null && ! $user->mfaEnabled(),
            'confirmed_at' => $user->mfa_confirmed_at?->toIso8601String(),
            'recovery_codes_remaining' => $user->mfaEnabled() ? $mfa->recoveryCodesRemaining($user) : 0,
        ]])->header('Cache-Control', 'no-store');
    }

    public function setup(BeginMfaSetupRequest $request, TotpService $totp): JsonResponse
    {
        /** @var User $requestUser */
        $requestUser = $request->user();
        $secret = DB::transaction(function () use ($request, $requestUser, $totp): string {
            $user = User::query()->lockForUpdate()->findOrFail($requestUser->getKey());
            if (! Hash::check((string) $request->validated('current_password'), $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['The current password is incorrect.'],
                ]);
            }
            if ($user->mfaEnabled()) {
                throw ValidationException::withMessages([
                    'current_password' => ['Disable multi-factor authentication before replacing its authenticator.'],
                ]);
            }

            $secret = $totp->generateSecret();
            $user->forceFill([
                'mfa_secret' => $secret,
                'mfa_confirmed_at' => null,
                'mfa_last_used_step' => null,
            ])->save();
            UserMfaRecoveryCode::query()->where('user_id', $user->getKey())->delete();

            return $secret;
        });

        // The enrollment secret is intentionally a one-response value. It is
        // encrypted at rest and prohibited from caches and browser persistence.
        return response()->json(['data' => [
            'secret' => $secret,
            'otpauth_uri' => $totp->provisioningUri($secret, $requestUser->email),
            'issuer' => 'IronCore',
            'account' => $requestUser->email,
        ]])->withHeaders(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    public function confirm(
        ConfirmMfaSetupRequest $request,
        TotpService $totp,
        MfaService $mfa,
        AuditService $audit,
    ): JsonResponse {
        /** @var User $requestUser */
        $requestUser = $request->user();
        $currentTokenId = $requestUser->currentPersonalAccessTokenId();

        [$user, $recoveryCodes] = DB::transaction(function () use ($request, $requestUser, $currentTokenId, $totp, $mfa, $audit): array {
            $user = User::query()->lockForUpdate()->findOrFail($requestUser->getKey());
            if ($user->mfa_secret === null || $user->mfaEnabled()) {
                throw ValidationException::withMessages([
                    'code' => ['Start authenticator setup before confirming a code.'],
                ]);
            }

            $counter = $totp->matchingCounter((string) $user->mfa_secret, (string) $request->validated('code'));
            if ($counter === null) {
                throw ValidationException::withMessages([
                    'code' => ['The authentication code is invalid or has expired.'],
                ]);
            }

            $user->forceFill([
                'mfa_confirmed_at' => now(),
                'mfa_last_used_step' => $counter,
            ])->save();
            $recoveryCodes = $mfa->replaceRecoveryCodes($user);
            $this->rotateOtherCredentials($user, $currentTokenId);
            $audit->record('account.mfa.enabled', $user, $user, [], [
                'enabled' => true,
                'recovery_code_count' => count($recoveryCodes),
            ], request: $request);

            return [$user, $recoveryCodes];
        });

        $this->refreshCurrentSessionGeneration($request, $user, $currentTokenId);

        return response()->json(['data' => [
            'enabled' => true,
            'recovery_codes' => $recoveryCodes,
            'recovery_codes_remaining' => count($recoveryCodes),
        ]])->withHeaders(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    public function regenerateRecoveryCodes(
        RegenerateMfaRecoveryCodesRequest $request,
        MfaService $mfa,
        AuditService $audit,
    ): JsonResponse {
        /** @var User $requestUser */
        $requestUser = $request->user();

        $recoveryCodes = DB::transaction(function () use ($request, $requestUser, $mfa, $audit): array {
            $user = User::query()->lockForUpdate()->findOrFail($requestUser->getKey());
            $this->assertCurrentPassword($user, (string) $request->validated('current_password'));
            $mfa->verifySecondFactor($user, (string) $request->validated('code'), null);
            $codes = $mfa->replaceRecoveryCodes($user);
            $audit->record('account.mfa.recovery_codes_regenerated', $user, $user, [], [
                'recovery_code_count' => count($codes),
            ], request: $request);

            return $codes;
        });

        return response()->json(['data' => [
            'recovery_codes' => $recoveryCodes,
            'recovery_codes_remaining' => count($recoveryCodes),
        ]])->withHeaders(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    public function destroy(DisableMfaRequest $request, MfaService $mfa, AuditService $audit): JsonResponse
    {
        /** @var User $requestUser */
        $requestUser = $request->user();
        $currentTokenId = $requestUser->currentPersonalAccessTokenId();

        $user = DB::transaction(function () use ($request, $requestUser, $currentTokenId, $mfa, $audit): User {
            $user = User::query()->lockForUpdate()->findOrFail($requestUser->getKey());
            $this->assertCurrentPassword($user, (string) $request->validated('current_password'));
            $mfa->verifySecondFactor(
                $user,
                $request->validated('code'),
                $request->validated('recovery_code'),
            );
            UserMfaRecoveryCode::query()->where('user_id', $user->getKey())->delete();
            $user->forceFill([
                'mfa_secret' => null,
                'mfa_confirmed_at' => null,
                'mfa_last_used_step' => null,
            ])->save();
            $this->rotateOtherCredentials($user, $currentTokenId);
            $audit->record('account.mfa.disabled', $user, $user, ['enabled' => true], ['enabled' => false], request: $request);

            return $user;
        });

        $this->refreshCurrentSessionGeneration($request, $user, $currentTokenId);

        return response()->json([
            'message' => 'Multi-factor authentication was disabled. Other signed-in sessions were revoked.',
        ]);
    }

    public function challenge(
        VerifyMfaChallengeRequest $request,
        MfaChallengeService $challenges,
    ): JsonResponse {
        $result = $challenges->consume(
            (string) $request->validated('challenge_token'),
            $request->validated('code'),
            $request->validated('recovery_code'),
        );
        $user = $result['user'];

        if ($result['uses_bearer_token']) {
            $user->tokens()->where('name', $result['device_name'])->delete();
            $token = $user->createToken($result['device_name'], ['app:use'])->plainTextToken;

            return response()->json(['data' => [
                'authentication' => 'bearer',
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => $this->userPayload($user->load('gyms')),
            ]])->header('Cache-Control', 'no-store');
        }

        if (! $request->hasSession()) {
            return response()->json([
                'message' => 'A stateful browser origin is required for session login.',
            ], Response::HTTP_BAD_REQUEST);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(User::SESSION_AUTH_VERSION_KEY, $user->auth_version);

        return response()->json(['data' => [
            'authentication' => 'session',
            'user' => $this->userPayload($user->load('gyms')),
        ]])->header('Cache-Control', 'no-store');
    }

    private function rotateOtherCredentials(User $user, ?int $currentTokenId): void
    {
        $user->forceFill(['auth_version' => $user->auth_version + 1])->save();
        if ($currentTokenId !== null) {
            $user->tokens()->where('id', '!=', $currentTokenId)->delete();
        } else {
            $user->tokens()->delete();
        }
    }

    private function refreshCurrentSessionGeneration(Request $request, User $user, ?int $currentTokenId): void
    {
        if ($currentTokenId === null) {
            $request->session()->regenerate();
            $request->session()->put(User::SESSION_AUTH_VERSION_KEY, $user->auth_version);
        }
    }

    private function assertCurrentPassword(User $user, string $password): void
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }
    }

    private function userPayload(User $user): array
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
