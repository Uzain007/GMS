<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserMfaRecoveryCode;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

final class MfaService
{
    public const RECOVERY_CODE_COUNT = 8;

    public function __construct(private readonly TotpService $totp) {}

    public function verifySecondFactor(User $user, ?string $code, ?string $recoveryCode): string
    {
        if (! $user->mfaEnabled()) {
            $this->invalidCode();
        }

        if (is_string($code) && $code !== '') {
            $counter = $this->totp->matchingCounter(
                (string) $user->mfa_secret,
                $code,
                $user->mfa_last_used_step,
            );
            if ($counter === null) {
                $this->invalidCode();
            }

            // The caller holds a user row lock. Advancing the counter inside
            // that transaction prevents two requests accepting one TOTP step.
            $user->forceFill(['mfa_last_used_step' => $counter])->save();

            return 'totp';
        }

        if (is_string($recoveryCode) && $recoveryCode !== '') {
            $digest = $this->recoveryCodeDigest($recoveryCode);
            $stored = UserMfaRecoveryCode::query()
                ->where('user_id', $user->getKey())
                ->where('code_hash', $digest)
                ->whereNull('used_at')
                ->lockForUpdate()
                ->first();

            if ($stored === null || ! hash_equals($stored->code_hash, $digest)) {
                $this->invalidCode();
            }

            $stored->forceFill(['used_at' => now()])->save();

            return 'recovery_code';
        }

        $this->invalidCode();
    }

    /** @return list<string> */
    public function replaceRecoveryCodes(User $user): array
    {
        UserMfaRecoveryCode::query()->where('user_id', $user->getKey())->delete();
        $plainCodes = [];

        for ($index = 0; $index < self::RECOVERY_CODE_COUNT; $index += 1) {
            $plain = $this->totp->randomRecoveryCode();
            $plainCodes[] = $plain;
            UserMfaRecoveryCode::query()->create([
                'user_id' => $user->getKey(),
                'code_hash' => $this->recoveryCodeDigest($plain),
            ]);
        }

        return $plainCodes;
    }

    public function recoveryCodesRemaining(User $user): int
    {
        return UserMfaRecoveryCode::query()
            ->where('user_id', $user->getKey())
            ->whereNull('used_at')
            ->count();
    }

    private function recoveryCodeDigest(string $plain): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $plain) ?? '');
        $applicationKey = (string) config('app.key');
        if (str_starts_with($applicationKey, 'base64:')) {
            $decoded = base64_decode(substr($applicationKey, 7), true);
            $applicationKey = $decoded === false ? $applicationKey : $decoded;
        }

        if ($applicationKey === '') {
            // Match Laravel's own encrypted-cast failure mode rather than
            // silently creating unkeyed recovery digests.
            Crypt::encryptString('mfa-key-check');
        }

        return hash_hmac('sha256', $normalized, $applicationKey);
    }

    private function invalidCode(): never
    {
        throw ValidationException::withMessages([
            'code' => ['The authentication code is invalid or has expired.'],
        ]);
    }
}
