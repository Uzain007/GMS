<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\SendPasswordResetLink;
use App\Models\Gym;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('recoverableRoles')]
    public function test_reset_email_is_available_to_every_identity_role(UserRole $role): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'email' => $role->value.'@example.test',
            'platform_role' => $role === UserRole::SuperAdmin ? UserRole::SuperAdmin : null,
        ]);

        if ($role !== UserRole::SuperAdmin) {
            $gym = Gym::factory()->create();
            app(TenantContext::class)->run($gym, fn () => $gym->users()->attach($user->id, [
                'role' => $role->value,
                'status' => 'active',
                'joined_at' => now(),
            ]));
        }

        (new SendPasswordResetLink($user->email))->handle();

        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
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

    public function test_reset_token_expires_and_a_consumed_token_cannot_be_reused(): void
    {
        $user = User::factory()->create(['email' => 'one-time@example.test']);
        $expiredToken = Password::createToken($user);

        $this->travel(((int) config('auth.passwords.users.expire')) + 1)->minutes();
        $this->withHeaders($this->browserHeaders())->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $expiredToken,
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.token.0', 'This password reset link is invalid or has expired.');
        $this->travelBack();

        $validToken = Password::createToken($user);
        $payload = [
            'email' => $user->email,
            'token' => $validToken,
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
        ];

        $this->withHeaders($this->browserHeaders())->postJson('/api/v1/auth/reset-password', $payload)
            ->assertOk();
        $this->withHeaders($this->browserHeaders())->postJson('/api/v1/auth/reset-password', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.token.0', 'This password reset link is invalid or has expired.');
    }

    public function test_user_can_log_in_with_new_password_but_not_the_old_password(): void
    {
        $user = User::factory()->create([
            'email' => 'new-password@example.test',
            'password' => Hash::make('OldSecurePassword123!'),
        ]);
        $token = Password::createToken($user);

        $this->withHeaders($this->browserHeaders())->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
        ])->assertOk();

        $this->withHeaders($this->browserHeaders())->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->withHeaders($this->browserHeaders())->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'OldSecurePassword123!',
        ])->assertUnprocessable();
        $this->withHeaders($this->browserHeaders())->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'NewSecurePassword123!',
        ])->assertOk()->assertJsonPath('data.authentication', 'session');
    }

    public function test_password_change_keeps_current_session_and_revokes_other_credentials(): void
    {
        $user = User::factory()->create();
        $user->createToken('phone');
        $this->withHeaders($this->browserHeaders())
            ->actingAs($user)
            ->withSession([User::SESSION_AUTH_VERSION_KEY => 1]);

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
        $this->withHeaders($this->browserHeaders())
            ->actingAs($user)
            ->withSession([User::SESSION_AUTH_VERSION_KEY => 1]);
        $user->forceFill(['auth_version' => 2])->save();

        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'This session is no longer valid. Please sign in again.');
        $this->assertGuest();
    }

    /** @return array<string, array{UserRole}> */
    public static function recoverableRoles(): array
    {
        return [
            'Super Admin' => [UserRole::SuperAdmin],
            'Gym Admin' => [UserRole::GymOwner],
            'Staff / Reception' => [UserRole::Receptionist],
            'Trainer' => [UserRole::Trainer],
            'Member' => [UserRole::Member],
        ];
    }

    /** @return array<string, string> */
    private function browserHeaders(): array
    {
        return ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'];
    }
}
