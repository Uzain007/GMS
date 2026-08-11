<?php

namespace Tests\Feature;

use App\Jobs\SendPasswordResetLink;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PhaseNineAccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovery_acknowledgement_is_identical_for_known_and_unknown_emails(): void
    {
        Queue::fake();
        User::factory()->create(['email' => 'known@example.test']);

        $known = $this->withHeaders($this->browserHeaders())->postJson('/api/v1/auth/forgot-password', [
            'email' => ' Known@Example.Test ',
        ]);
        $unknown = $this->withHeaders($this->browserHeaders())->postJson('/api/v1/auth/forgot-password', [
            'email' => 'unknown@example.test',
        ]);

        $known->assertAccepted();
        $unknown->assertAccepted();
        $this->assertSame($known->json(), $unknown->json());
        Queue::assertPushed(SendPasswordResetLink::class, 2);
        Queue::assertPushed(SendPasswordResetLink::class, fn (SendPasswordResetLink $job) => $job->email === 'known@example.test');
        Queue::assertPushed(SendPasswordResetLink::class, fn (SendPasswordResetLink $job) => $job->email === 'unknown@example.test');
    }

    public function test_reset_worker_uses_a_fragment_only_frontend_link(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'member@example.test']);

        (new SendPasswordResetLink($user->email))->handle();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $url = (string) $notification->toMail($user)->actionUrl;

            return str_contains($url, '/#reset_email=member%40example.test&reset_token=')
                && ! str_contains($url, '?token=');
        });
    }

    public function test_valid_reset_replaces_password_and_revokes_every_previous_credential(): void
    {
        $user = User::factory()->create(['email' => 'reset@example.test']);
        $user->createToken('phone');
        $user->createToken('tablet');
        $token = Password::createToken($user);

        $this->withHeaders($this->browserHeaders())->postJson('/api/v1/auth/reset-password', [
            'email' => 'reset@example.test',
            'token' => $token,
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
        ])->assertOk()->assertJsonPath('data.authentication', 'session');

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('NewSecurePassword123!', $fresh->password));
        $this->assertSame(2, $fresh->auth_version);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'reset@example.test']);
        $this->assertAuthenticatedAs($fresh);
        $this->assertSame(2, session(User::SESSION_AUTH_VERSION_KEY));
    }

    public function test_invalid_reset_token_returns_one_generic_validation_error(): void
    {
        User::factory()->create(['email' => 'reset@example.test']);

        $this->withHeaders($this->browserHeaders())->postJson('/api/v1/auth/reset-password', [
            'email' => 'reset@example.test',
            'token' => 'invalid-token',
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.token.0', 'This password reset link is invalid or has expired.');
    }

    public function test_password_change_keeps_current_session_and_revokes_other_credentials(): void
    {
        $user = User::factory()->create();
        $user->createToken('phone');
        $this->actingAs($user)->withSession([User::SESSION_AUTH_VERSION_KEY => 1]);

        $this->patchJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'password' => 'ChangedSecurePassword123!',
            'password_confirmation' => 'ChangedSecurePassword123!',
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('ChangedSecurePassword123!', $fresh->password));
        $this->assertSame(2, $fresh->auth_version);
        $this->assertSame(2, session(User::SESSION_AUTH_VERSION_KEY));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertAuthenticatedAs($fresh);
    }

    public function test_stale_session_generation_is_rejected_before_identity_binding(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession([User::SESSION_AUTH_VERSION_KEY => 1]);
        $user->forceFill(['auth_version' => 2])->save();

        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'This session is no longer valid. Please sign in again.');
        $this->assertGuest();
    }

    /** @return array<string, string> */
    private function browserHeaders(): array
    {
        return ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'];
    }
}
