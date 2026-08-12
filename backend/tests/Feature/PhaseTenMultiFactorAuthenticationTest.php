<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MfaService;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PhaseTenMultiFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_enrollment_encrypts_the_secret_hashes_recovery_codes_and_revokes_other_credentials(): void
    {
        Carbon::setTestNow('2026-08-11 12:00:00 UTC');
        $user = User::factory()->create();
        $user->createToken('phone');
        $this->withHeaders($this->browserHeaders())
            ->actingAs($user)
            ->withSession([User::SESSION_AUTH_VERSION_KEY => 1]);

        $setupResponse = $this->postJson('/api/v1/auth/mfa/setup', ['current_password' => 'password'])
            ->assertOk();
        $this->assertStringContainsString('no-store', (string) $setupResponse->headers->get('Cache-Control'));
        $setup = $setupResponse->json('data');
        $this->assertStringStartsWith('otpauth://totp/', $setup['otpauth_uri']);
        $this->assertNotSame($setup['secret'], $user->fresh()->getRawOriginal('mfa_secret'));

        $code = app(TotpService::class)->codeForTimestamp($setup['secret'], now()->getTimestamp());
        $response = $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonCount(MfaService::RECOVERY_CODE_COUNT, 'data.recovery_codes');

        $plainRecoveryCodes = $response->json('data.recovery_codes');
        $fresh = $user->fresh();
        $this->assertTrue($fresh->mfaEnabled());
        $this->assertSame(2, $fresh->auth_version);
        $this->assertSame(2, session(User::SESSION_AUTH_VERSION_KEY));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('user_mfa_recovery_codes', MfaService::RECOVERY_CODE_COUNT);
        $this->assertDatabaseMissing('user_mfa_recovery_codes', [
            'code_hash' => str_replace('-', '', $plainRecoveryCodes[0]),
        ]);
        $this->assertOwnPlatformAudit($user, 'account.mfa.enabled');
    }

    public function test_password_login_returns_an_opaque_challenge_and_totp_is_one_time(): void
    {
        Carbon::setTestNow('2026-08-11 12:00:00 UTC');
        [$user, $secret] = $this->enrollMfa();
        $this->postJson('/api/v1/auth/logout')->assertNoContent();

        Carbon::setTestNow(now()->addSeconds(31));
        $login = $this->withHeaders($this->browserHeaders())->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'use_bearer_token' => false,
        ])->assertAccepted()
            ->assertJsonPath('data.authentication', 'mfa_challenge')
            ->assertJsonMissingPath('data.user')
            ->assertJsonMissingPath('data.token');
        $this->assertGuest();

        $challenge = $login->json('data.challenge_token');
        $code = app(TotpService::class)->codeForTimestamp($secret, now()->getTimestamp());
        $this->withHeaders($this->browserHeaders())->postJson('/api/v1/auth/mfa/challenge', [
            'challenge_token' => $challenge,
            'code' => $code,
        ])->assertOk()->assertJsonPath('data.authentication', 'session');
        $this->assertAuthenticatedAs($user);

        $this->withHeaders($this->browserHeaders())->postJson('/api/v1/auth/mfa/challenge', [
            'challenge_token' => $challenge,
            'code' => $code,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'The authentication code is invalid or has expired.');
    }

    public function test_a_recovery_code_authenticates_once_and_is_consumed_under_the_user(): void
    {
        Carbon::setTestNow('2026-08-11 12:00:00 UTC');
        [$user, , $recoveryCodes] = $this->enrollMfa();
        $this->postJson('/api/v1/auth/logout')->assertNoContent();

        $login = $this->withHeaders($this->browserHeaders())->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertAccepted();
        $this->withHeaders($this->browserHeaders())->postJson('/api/v1/auth/mfa/challenge', [
            'challenge_token' => $login->json('data.challenge_token'),
            'recovery_code' => $recoveryCodes[0],
        ])->assertOk();

        $this->assertSame(MfaService::RECOVERY_CODE_COUNT - 1, $user->mfaRecoveryCodes()->whereNull('used_at')->count());
        $this->assertSame(1, $user->mfaRecoveryCodes()->whereNotNull('used_at')->count());
    }

    public function test_password_reset_keeps_an_enabled_factor_between_recovery_and_session_creation(): void
    {
        Carbon::setTestNow('2026-08-11 12:00:00 UTC');
        [$user] = $this->enrollMfa();
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $token = Password::createToken($user);

        $this->withHeaders($this->browserHeaders())->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'ReplacementPassword123!',
            'password_confirmation' => 'ReplacementPassword123!',
        ])->assertAccepted()
            ->assertJsonPath('data.authentication', 'mfa_challenge')
            ->assertJsonMissingPath('data.user');

        $this->assertGuest();
        $this->assertTrue($user->fresh()->mfaEnabled());
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_disable_requires_password_and_second_factor_then_revokes_other_credentials(): void
    {
        Carbon::setTestNow('2026-08-11 12:00:00 UTC');
        [$user, $secret] = $this->enrollMfa();
        $user->createToken('tablet');
        Carbon::setTestNow(now()->addSeconds(31));
        $code = app(TotpService::class)->codeForTimestamp($secret, now()->getTimestamp());

        $this->deleteJson('/api/v1/auth/mfa', [
            'current_password' => 'password',
            'code' => $code,
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertFalse($fresh->mfaEnabled());
        $this->assertNull($fresh->mfa_secret);
        $this->assertSame(3, $fresh->auth_version);
        $this->assertSame(3, session(User::SESSION_AUTH_VERSION_KEY));
        $this->assertDatabaseCount('user_mfa_recovery_codes', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertOwnPlatformAudit($user, 'account.mfa.disabled');
    }

    /** @return array{User,string,list<string>} */
    private function enrollMfa(): array
    {
        $user = User::factory()->create();
        $this->withHeaders($this->browserHeaders())
            ->actingAs($user)
            ->withSession([User::SESSION_AUTH_VERSION_KEY => 1]);
        $setup = $this->postJson('/api/v1/auth/mfa/setup', ['current_password' => 'password'])
            ->assertOk()
            ->json('data');
        $code = app(TotpService::class)->codeForTimestamp($setup['secret'], now()->getTimestamp());
        $confirmation = $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $code])->assertOk();

        return [$user->fresh(), $setup['secret'], $confirmation->json('data.recovery_codes')];
    }

    /** @return array<string,string> */
    private function browserHeaders(): array
    {
        return ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'];
    }

    private function assertOwnPlatformAudit(User $user, string $event): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("select set_config('ironcore.current_user_id', ?, false)", [$user->id]);
        }

        try {
            $this->assertDatabaseHas('audit_logs', ['event' => $event, 'actor_id' => $user->id]);
        } finally {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("select set_config('ironcore.current_user_id', '', false)");
            }
        }
    }
}
