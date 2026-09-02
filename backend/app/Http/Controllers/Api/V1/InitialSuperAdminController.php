<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\InitialSuperAdminRequest;
use App\Models\User;
use App\Services\AuditService;
use App\Support\DatabaseIdentityContext;
use Illuminate\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class InitialSuperAdminController extends Controller
{
    public function status(): JsonResponse
    {
        return response()->json([
            'data' => [
                'setup_required' => ! $this->superAdminExists(),
                'setup_available' => $this->configuredKeyHash() !== null,
            ],
        ])->withHeaders(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    public function store(
        InitialSuperAdminRequest $request,
        AuditService $audit,
        DatabaseIdentityContext $identity,
    ): JsonResponse {
        if (! $request->hasSession()) {
            return response()->json(['message' => 'A stateful browser origin is required for initial setup.'], 400);
        }

        $expectedHash = $this->configuredKeyHash();
        if ($expectedHash === null) {
            return response()->json(['message' => 'Initial owner setup is not configured on this deployment.'], 503);
        }

        if (! hash_equals($expectedHash, hash('sha256', (string) $request->string('setup_key')))) {
            throw ValidationException::withMessages(['setup_key' => ['The owner setup key is invalid.']]);
        }

        try {
            // The platform-wide Redis lock serializes fresh-install claims
            // across API replicas. The database check inside the lock closes
            // the endpoint permanently as soon as platform ownership exists.
            $user = Cache::lock('ironcore:platform:initial-super-admin', 15)->block(5, function () use ($request, $audit, $identity): User {
                if ($this->superAdminExists()) {
                    abort(409, 'Initial owner setup is no longer available.');
                }

                return DB::transaction(function () use ($request, $audit, $identity): User {
                    $user = User::query()->create([
                        'name' => (string) $request->string('name'),
                        'email' => (string) $request->string('email'),
                        'password' => Hash::make((string) $request->string('password')),
                        'platform_role' => UserRole::SuperAdmin,
                    ]);

                    $identity->run($user, fn () => $audit->record(
                        'platform.initial_super_admin.created',
                        $user,
                        $user,
                        after: ['platform_role' => UserRole::SuperAdmin->value],
                        reason: 'Initial platform ownership established',
                        request: $request,
                    ));

                    return $user;
                });
            });
        } catch (LockTimeoutException) {
            return response()->json(['message' => 'Initial owner setup is already in progress. Please retry shortly.'], 409);
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
                    'platform_role' => UserRole::SuperAdmin->value,
                    'gyms' => [],
                ],
            ],
        ], 201)->withHeaders(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    private function configuredKeyHash(): ?string
    {
        $hash = mb_strtolower(trim((string) config('initial_setup.key_hash')));

        return preg_match('/\A[a-f0-9]{64}\z/', $hash) === 1 ? $hash : null;
    }

    private function superAdminExists(): bool
    {
        // Tenant accounts can exist before platform ownership is claimed; only
        // an existing platform Super Admin closes this one-time setup route.
        return User::query()->where('platform_role', UserRole::SuperAdmin->value)->exists();
    }
}
