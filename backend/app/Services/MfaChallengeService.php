<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MfaChallengeService
{
    public const TTL_SECONDS = 300;
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly MfaService $mfa) {}

    /** @return array{authentication:string,mfa_required:bool,challenge_token:string,expires_in:int} */
    public function create(User $user, bool $usesBearerToken = false, string $deviceName = 'native-client'): array
    {
        $plainChallenge = bin2hex(random_bytes(32));
        $expiresAt = now()->addSeconds(self::TTL_SECONDS)->getTimestamp();
        Cache::put($this->cacheKey($plainChallenge), [
            'user_id' => (string) $user->getKey(),
            'auth_version' => (int) $user->auth_version,
            'uses_bearer_token' => $usesBearerToken,
            'device_name' => mb_substr($deviceName, 0, 120),
            'attempts' => 0,
            'expires_at' => $expiresAt,
        ], self::TTL_SECONDS);

        return [
            'authentication' => 'mfa_challenge',
            'mfa_required' => true,
            'challenge_token' => $plainChallenge,
            'expires_in' => self::TTL_SECONDS,
        ];
    }

    /** @return array{user:User,uses_bearer_token:bool,device_name:string} */
    public function consume(string $plainChallenge, ?string $code, ?string $recoveryCode): array
    {
        $cacheKey = $this->cacheKey($plainChallenge);

        try {
            return Cache::lock($cacheKey.':lock', 5)->block(2, function () use ($cacheKey, $code, $recoveryCode): array {
                $payload = Cache::get($cacheKey);
                if (! is_array($payload) || (int) ($payload['expires_at'] ?? 0) < now()->getTimestamp()) {
                    Cache::forget($cacheKey);
                    $this->invalidCode();
                }

                try {
                    $user = DB::transaction(function () use ($payload, $code, $recoveryCode): User {
                        $user = User::query()->lockForUpdate()->find($payload['user_id'] ?? null);
                        if ($user === null
                            || ! $user->mfaEnabled()
                            || (int) $user->auth_version !== (int) ($payload['auth_version'] ?? -1)) {
                            $this->invalidCode();
                        }

                        $this->mfa->verifySecondFactor($user, $code, $recoveryCode);

                        return $user;
                    });
                } catch (ValidationException $exception) {
                    $attempts = (int) ($payload['attempts'] ?? 0) + 1;
                    if ($attempts >= self::MAX_ATTEMPTS) {
                        Cache::forget($cacheKey);
                    } else {
                        $payload['attempts'] = $attempts;
                        $remaining = max(1, (int) $payload['expires_at'] - now()->getTimestamp());
                        Cache::put($cacheKey, $payload, $remaining);
                    }
                    throw $exception;
                }

                // Consume the opaque challenge before issuing any authenticated
                // context so concurrent replays cannot mint two credentials.
                Cache::forget($cacheKey);

                return [
                    'user' => $user,
                    'uses_bearer_token' => (bool) ($payload['uses_bearer_token'] ?? false),
                    'device_name' => (string) ($payload['device_name'] ?? 'native-client'),
                ];
            });
        } catch (LockTimeoutException) {
            $this->invalidCode();
        }
    }

    private function cacheKey(string $plainChallenge): string
    {
        return 'ironcore:auth:mfa:challenge:'.hash('sha256', $plainChallenge);
    }

    private function invalidCode(): never
    {
        throw ValidationException::withMessages([
            'code' => ['The authentication code is invalid or has expired.'],
        ]);
    }
}
