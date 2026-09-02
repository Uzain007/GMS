<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Gym;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InitialSuperAdminSetupTest extends TestCase
{
    use RefreshDatabase;

    private string $setupKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setupKey = str_repeat('setup-owner-', 4);
        config(['initial_setup.key_hash' => hash('sha256', $this->setupKey)]);
    }

    public function test_fresh_install_reports_that_owner_setup_is_required_and_configured(): void
    {
        $this->getJson('/api/v1/setup/super-admin')
            ->assertOk()
            ->assertJsonPath('data.setup_required', true)
            ->assertJsonPath('data.setup_available', true)
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_setup_is_unavailable_without_a_valid_configured_key_hash(): void
    {
        config(['initial_setup.key_hash' => null]);

        $this->getJson('/api/v1/setup/super-admin')
            ->assertOk()
            ->assertJsonPath('data.setup_required', true)
            ->assertJsonPath('data.setup_available', false);

        $this->browserPost($this->validPayload())->assertServiceUnavailable();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_invalid_setup_key_and_weak_account_details_are_rejected(): void
    {
        $payload = $this->validPayload();
        $payload['setup_key'] = str_repeat('wrong-', 7);

        $this->browserPost($payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('setup_key');
        $this->assertDatabaseCount('users', 0);

        $payload = $this->validPayload();
        $payload['email'] = 'not-an-email';
        $payload['password'] = 'too-short';
        $payload['password_confirmation'] = 'different';

        $this->browserPost($payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_owner_can_create_exactly_one_super_admin_and_start_a_session(): void
    {
        $response = $this->browserPost($this->validPayload());

        $response->assertCreated()
            ->assertJsonPath('data.authentication', 'session')
            ->assertJsonPath('data.user.email', 'owner@example.test')
            ->assertJsonPath('data.user.platform_role', UserRole::SuperAdmin->value)
            ->assertJsonPath('data.user.gyms', [])
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.user.password');

        $user = User::query()->sole();
        $this->assertSame(UserRole::SuperAdmin, $user->platform_role);
        $this->assertTrue(Hash::check('OwnerPassword9!', $user->password));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $user->id,
            'event' => 'platform.initial_super_admin.created',
        ]);

        $this->getJson('/api/v1/setup/super-admin')
            ->assertOk()
            ->assertJsonPath('data.setup_required', false);

        $this->browserPost([
            ...$this->validPayload(),
            'email' => 'second-owner@example.test',
        ])->assertConflict();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_an_existing_tenant_identity_does_not_block_platform_owner_setup_or_change_normal_login(): void
    {
        $member = User::factory()->create([
            'email' => 'member@example.test',
            'password' => 'ExistingMember9!',
            'platform_role' => null,
        ]);

        $this->getJson('/api/v1/setup/super-admin')
            ->assertOk()
            ->assertJsonPath('data.setup_required', true);

        $this->browserPost($this->validPayload())->assertCreated();
        $this->assertDatabaseCount('users', 2);

        $this->withHeaders($this->browserHeaders())->postJson('/api/v1/auth/login', [
            'email' => 'member@example.test',
            'password' => 'ExistingMember9!',
            'use_bearer_token' => false,
        ])->assertOk()->assertJsonPath('data.user.id', $member->id);
    }

    public function test_setup_rejects_an_email_already_used_by_a_tenant_identity(): void
    {
        User::factory()->create([
            'email' => 'owner@example.test',
            'platform_role' => null,
        ]);

        $this->browserPost($this->validPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertSame(0, User::query()->where('platform_role', UserRole::SuperAdmin->value)->count());
    }

    #[DataProvider('tenantRoles')]
    public function test_existing_gym_admin_staff_trainer_and_member_logins_are_unchanged(UserRole $role): void
    {
        $user = User::factory()->create([
            'email' => $role->value.'@example.test',
            'password' => 'ExistingAccount9!',
            'platform_role' => null,
        ]);
        $gym = Gym::factory()->create();
        app(TenantContext::class)->run($gym, fn () => $gym->users()->attach($user->id, [
            'role' => $role->value,
            'status' => 'active',
            'joined_at' => now(),
        ]));

        $this->withHeaders($this->browserHeaders())->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'ExistingAccount9!',
            'use_bearer_token' => false,
        ])->assertOk()
            ->assertJsonPath('data.user.gyms.0.id', $gym->id)
            ->assertJsonPath('data.user.gyms.0.role', $role->value);
    }

    public static function tenantRoles(): array
    {
        return [
            'Gym Admin' => [UserRole::GymOwner],
            'Reception staff' => [UserRole::Receptionist],
            'Trainer' => [UserRole::Trainer],
            'Member' => [UserRole::Member],
        ];
    }

    private function browserPost(array $payload)
    {
        return $this->withHeaders($this->browserHeaders())
            ->postJson('/api/v1/setup/super-admin', $payload);
    }

    private function browserHeaders(): array
    {
        return [
            'Origin' => 'http://localhost:3000',
            'Referer' => 'http://localhost:3000/',
        ];
    }

    private function validPayload(): array
    {
        return [
            'setup_key' => $this->setupKey,
            'name' => 'Platform Owner',
            'email' => 'owner@example.test',
            'password' => 'OwnerPassword9!',
            'password_confirmation' => 'OwnerPassword9!',
        ];
    }
}
