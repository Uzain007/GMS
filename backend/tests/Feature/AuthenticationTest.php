<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Gym;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_credentials_start_a_stateful_web_session_without_exposing_a_token(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders([
            'Origin' => 'http://localhost:3000',
            'Referer' => 'http://localhost:3000/',
        ])->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'use_bearer_token' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.authentication', 'session')
            ->assertJsonMissingPath('data.token');
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_payload_includes_the_users_active_gym_under_postgresql_rls(): void
    {
        $user = User::factory()->create();
        $gym = Gym::factory()->create();
        app(TenantContext::class)->run($gym, fn () => $gym->users()->attach($user->id, [
            'role' => UserRole::GymOwner->value,
            'status' => 'active',
            'joined_at' => now(),
        ]));

        $this->withHeaders([
            'Origin' => 'http://localhost:3000',
            'Referer' => 'http://localhost:3000/',
        ])->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'use_bearer_token' => false,
        ])->assertOk()
            ->assertJsonPath('data.user.gyms.0.id', $gym->id)
            ->assertJsonPath('data.user.gyms.0.role', UserRole::GymOwner->value);
    }

    public function test_tenant_identity_remains_bound_after_the_session_lifetime_check(): void
    {
        [$user, $gym] = $this->tenantUser(UserRole::GymOwner);

        $this->statefulLogin($user)->assertOk();

        // The lifetime middleware runs inside the database-identity boundary.
        // Its role lookup must not clear that identity before tenant discovery.
        $this->getJson('/api/v1/gyms?per_page=100')->assertOk()
            ->assertJsonPath('data.0.id', $gym->id);
    }

    public function test_native_clients_can_explicitly_request_a_scoped_token(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'test-suite',
            'use_bearer_token' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.authentication', 'bearer')
            ->assertJsonPath('data.token_type', 'Bearer');
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id, 'name' => 'test-suite']);
    }

    public function test_invalid_credentials_do_not_reveal_the_account_state(): void
    {
        User::factory()->create(['email' => 'member@example.com']);
        $this->withHeaders([
            'Origin' => 'http://localhost:3000',
            'Referer' => 'http://localhost:3000/',
        ])->postJson('/api/v1/auth/login', ['email' => 'member@example.com', 'password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_member_browser_session_expires_after_six_hours(): void
    {
        CarbonImmutable::setTestNow('2026-09-14 08:00:00');
        [$user] = $this->tenantUser(UserRole::Member);

        $this->statefulLogin($user)->assertOk();
        CarbonImmutable::setTestNow('2026-09-14 14:00:00');

        $this->getJson('/api/v1/auth/me')->assertUnauthorized()
            ->assertJsonPath('code', 'session_expired');
        $this->assertGuest();
        CarbonImmutable::setTestNow();
    }

    public function test_staff_browser_session_remains_valid_before_ten_hours_then_expires(): void
    {
        CarbonImmutable::setTestNow('2026-09-14 08:00:00');
        [$user] = $this->tenantUser(UserRole::Receptionist);

        $this->statefulLogin($user)->assertOk();
        CarbonImmutable::setTestNow('2026-09-14 17:59:00');
        $this->getJson('/api/v1/auth/me')->assertOk();

        CarbonImmutable::setTestNow('2026-09-14 18:00:00');
        $this->getJson('/api/v1/auth/me')->assertUnauthorized()
            ->assertJsonPath('code', 'session_expired');
        CarbonImmutable::setTestNow();
    }

    public function test_expired_member_bearer_token_is_revoked_and_super_admin_is_unchanged(): void
    {
        [$member] = $this->tenantUser(UserRole::Member);
        $plainToken = $member->createToken('member-mobile', ['app:use'])->plainTextToken;
        $member->tokens()->latest('id')->firstOrFail()->forceFill(['created_at' => now()->subHours(7)])->save();

        $this->withToken($plainToken)->getJson('/api/v1/auth/me')->assertUnauthorized()
            ->assertJsonPath('code', 'session_expired');
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        $adminToken = $admin->createToken('platform-mobile', ['app:use'])->plainTextToken;
        $admin->tokens()->latest('id')->firstOrFail()->forceFill(['created_at' => now()->subDay()])->save();
        $this->withToken($adminToken)->getJson('/api/v1/auth/me')->assertOk();
    }

    /** @return array{User, Gym} */
    private function tenantUser(UserRole $role): array
    {
        $user = User::factory()->create();
        $gym = Gym::factory()->create();
        app(TenantContext::class)->run($gym, fn () => $gym->users()->attach($user->id, [
            'role' => $role->value,
            'status' => 'active',
            'joined_at' => now(),
        ]));

        return [$user, $gym];
    }

    private function statefulLogin(User $user)
    {
        return $this->withHeaders([
            'Origin' => 'http://localhost:3000',
            'Referer' => 'http://localhost:3000/',
        ])->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'use_bearer_token' => false,
        ]);
    }
}
