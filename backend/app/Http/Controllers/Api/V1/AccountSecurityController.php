<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Jobs\SendPasswordResetLink;
use App\Models\User;
use App\Services\MfaChallengeService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountSecurityController extends Controller
{
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        // Queue the lookup itself so response payload and mail-transport
        // behaviour cannot disclose whether this platform identity exists.
        SendPasswordResetLink::dispatch((string) $request->validated('email'));

        return response()->json([
            'message' => 'If an account matches that email, password reset instructions will be sent.',
        ], 202);
    }

    public function resetPassword(ResetPasswordRequest $request, MfaChallengeService $mfaChallenges): JsonResponse
    {
        if (! $request->hasSession()) {
            return response()->json([
                'message' => 'A stateful browser origin is required to reset a password.',
            ], 400);
        }

        $resetUser = null;
        $status = Password::reset(
            $request->safe()->only(['email', 'password', 'password_confirmation', 'token']),
            function (User $user, string $password) use (&$resetUser): void {
                $resetUser = DB::transaction(function () use ($user, $password): User {
                    $resetTokenTable = (string) config('auth.passwords.users.table', 'password_reset_tokens');
                    $resetToken = DB::table($resetTokenTable)
                        ->where('email', $user->getEmailForPasswordReset())
                        ->lockForUpdate()
                        ->first();

                    if ($resetToken === null) {
                        // A concurrent request already consumed this otherwise
                        // valid broker token. Preserve the generic denial path.
                        throw ValidationException::withMessages([
                            'token' => ['This password reset link is invalid or has expired.'],
                        ]);
                    }

                    $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
                    $lockedUser->forceFill([
                        'password' => $password,
                        'remember_token' => Str::random(60),
                        'auth_version' => $lockedUser->auth_version + 1,
                    ])->save();

                    // Recovery is a high-risk credential event: no existing
                    // native token or browser session survives it.
                    $lockedUser->tokens()->delete();
                    DB::table($resetTokenTable)->where('email', $user->getEmailForPasswordReset())->delete();

                    return $lockedUser;
                });

                event(new PasswordReset($resetUser));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'token' => ['This password reset link is invalid or has expired.'],
            ]);
        }

        if (! $resetUser instanceof User) {
            throw ValidationException::withMessages([
                'token' => ['This password reset link is invalid or has expired.'],
            ]);
        }

        if ($resetUser->mfaEnabled()) {
            // Email recovery replaces the password but never downgrades an
            // enrolled second factor or silently creates a full session.
            return response()->json([
                'data' => $mfaChallenges->create($resetUser),
                'message' => 'Your password was reset. Enter your authentication code to continue.',
            ], 202)->withHeaders(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
        }

        Auth::guard('web')->login($resetUser);
        $request->session()->regenerate();
        $request->session()->put(User::SESSION_AUTH_VERSION_KEY, $resetUser->auth_version);

        return response()->json([
            'data' => ['authentication' => 'session'],
            'message' => 'Your password has been reset securely.',
        ]);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $requestUser */
        $requestUser = $request->user();
        $currentTokenId = $requestUser->currentPersonalAccessTokenId();
        $user = DB::transaction(function () use ($request, $requestUser, $currentTokenId): User {
            // Serialize credential changes for this identity so concurrent
            // requests cannot lose an authentication-generation increment.
            $lockedUser = User::query()->lockForUpdate()->findOrFail($requestUser->getKey());

            if (! Hash::check((string) $request->validated('current_password'), $lockedUser->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['The current password is incorrect.'],
                ]);
            }

            $lockedUser->forceFill([
                'password' => (string) $request->validated('password'),
                'remember_token' => Str::random(60),
                'auth_version' => $lockedUser->auth_version + 1,
            ])->save();

            if ($currentTokenId !== null) {
                $lockedUser->tokens()->where('id', '!=', $currentTokenId)->delete();
            } else {
                $lockedUser->tokens()->delete();
            }

            return $lockedUser;
        });

        if ($currentTokenId === null) {
            $request->session()->regenerate();
            $request->session()->put(User::SESSION_AUTH_VERSION_KEY, $user->auth_version);
        }

        event(new PasswordReset($user));

        return response()->json([
            'message' => 'Your password was changed. Other signed-in sessions have been revoked.',
        ]);
    }
}
