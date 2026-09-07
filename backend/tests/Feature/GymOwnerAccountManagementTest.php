<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\SendPasswordResetLink;
use App\Models\AuditLog;
use App\Models\Gym;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GymOwnerAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_creates_manual_owner_and_owner_must_replace_temporary_password(): void
    {
        $superAdmin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        Sanctum::actingAs($superAdmin);

        $created = $this->postJson('/api/v1/gyms', $this->gymPayload([
            'setup_method' => 'temporary_password',
            'temporary_password' => 'Temporary!234',
            'temporary_password_confirmation' => 'Temporary!234',
            'require_password_change' => true,
        ]))->assertCreated()
            ->assertJsonPath('meta.owner_account.email', 'owner@example.test')
            ->assertJsonPath('meta.owner_account.setup_status', 'password_change_required')
            ->assertJsonMissingPath('meta.temporary_password');

        $gymId = $created->json('data.id');
        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('Temporary!234', $owner->password));
        $this->assertTrue($owner->must_change_password);

        auth()->guard('sanctum')->forgetUser();
        auth()->guard('web')->logout();
        $login = $this->withHeaders($this->browserHeaders())->postJson('/api/v1/auth/login', [
            'email' => 'owner@example.test',
            'password' => 'Temporary!234',
        ])->assertOk()
            ->assertJsonPath('data.user.must_change_password', true);

        $this->withHeaders($this->browserHeaders())->getJson("/api/v1/gyms/{$gymId}", ['X-Gym-ID' => $gymId])
            ->assertStatus(423)
            ->assertJsonPath('code', 'password_change_required');

        $this->withHeaders($this->browserHeaders())->patchJson('/api/v1/auth/password', [
            'current_password' => 'Temporary!234',
            'password' => 'PrivateOwner!567',
            'password_confirmation' => 'PrivateOwner!567',
        ])->assertOk();

        $this->withHeaders($this->browserHeaders())->getJson("/api/v1/gyms/{$gymId}", ['X-Gym-ID' => $gymId])->assertOk();
        $this->withHeaders($this->browserHeaders())->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->withHeaders($this->browserHeaders())->postJson('/api/v1/auth/login', [
            'email' => 'owner@example.test', 'password' => 'Temporary!234',
        ])->assertUnprocessable();
        $this->withHeaders($this->browserHeaders())->postJson('/api/v1/auth/login', [
            'email' => 'owner@example.test', 'password' => 'PrivateOwner!567',
        ])->assertOk()->assertJsonPath('data.user.gyms.0.id', $gymId);
    }

    public function test_invite_management_temporary_reset_and_tenant_denial_are_audited(): void
    {
        Queue::fake();
        $superAdmin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        Sanctum::actingAs($superAdmin);

        $created = $this->postJson('/api/v1/gyms', $this->gymPayload([
            'setup_method' => 'invite',
        ]))->assertCreated()
            ->assertJsonPath('meta.owner_account.setup_status', 'invite_pending')
            ->assertJsonMissing(['password', 'token']);
        $gymId = $created->json('data.id');
        Queue::assertPushed(SendPasswordResetLink::class, fn ($job) => $job->email === 'owner@example.test');

        $otherGym = Gym::factory()->create();
        $this->getJson("/api/v1/gyms/{$gymId}/owner-account", ['X-Gym-ID' => $gymId])
            ->assertOk()
            ->assertJsonPath('data.role', 'gym_owner')
            ->assertJsonPath('data.last_login_at', null);

        $this->patchJson("/api/v1/gyms/{$gymId}/owner-account", [
            'name' => 'Updated Owner',
            'email' => 'updated.owner@example.test',
            'phone' => '+44 7700 900002',
            'status' => 'suspended',
            'reason' => 'Owner access paused by platform support',
        ], ['X-Gym-ID' => $gymId])->assertOk()
            ->assertJsonPath('data.account_status', 'suspended')
            ->assertJsonPath('data.email', 'updated.owner@example.test');

        $this->postJson("/api/v1/gyms/{$gymId}/owner-account/password-reset", [], ['X-Gym-ID' => $gymId])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/gyms/{$gymId}/owner-account/password-reset", [
            'reason' => 'Owner requested a secure recovery email',
        ], ['X-Gym-ID' => $gymId])->assertOk();

        $temporaryResponse = $this->postJson("/api/v1/gyms/{$gymId}/owner-account/temporary-password", [
            'reason' => 'Verified owner requested immediate access recovery',
        ], ['X-Gym-ID' => $gymId])->assertOk()
            ->assertJsonPath('data.must_change_password', true);
        $this->assertStringContainsString('no-store', (string) $temporaryResponse->headers->get('Cache-Control'));
        $temporary = $temporaryResponse->json('meta.temporary_password');
        $this->assertIsString($temporary);
        $this->assertGreaterThanOrEqual(12, strlen($temporary));

        $this->patchJson("/api/v1/gyms/{$gymId}/owner-account", [
            'name' => 'Updated Owner',
            'email' => 'updated.owner@example.test',
            'phone' => '+44 7700 900002',
            'status' => 'active',
            'reason' => 'Identity verified and tenant access restored',
        ], ['X-Gym-ID' => $gymId])->assertOk();

        $owner = User::query()->where('email', 'updated.owner@example.test')->firstOrFail();
        auth()->guard('sanctum')->forgetUser();
        auth()->guard('web')->logout();
        $this->withHeaders($this->browserHeaders())->postJson('/api/v1/auth/login', [
            'email' => $owner->email, 'password' => $temporary,
        ])->assertOk()->assertJsonPath('data.user.gyms.0.id', $gymId);
        $this->withHeaders($this->browserHeaders())->patchJson('/api/v1/auth/password', [
            'current_password' => $temporary,
            'password' => 'OwnerPermanent!901',
            'password_confirmation' => 'OwnerPermanent!901',
        ])->assertOk();

        $this->withHeaders($this->browserHeaders())->getJson("/api/v1/gyms/{$otherGym->id}", ['X-Gym-ID' => $otherGym->id])
            ->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['gym_id' => $gymId, 'event' => 'gym.owner_account.updated']);
        $this->assertDatabaseHas('audit_logs', ['gym_id' => $gymId, 'event' => 'gym.owner_account.temporary_password_generated']);
    }

    public function test_gym_can_be_created_without_an_owner_then_receive_one_from_manage_gym(): void
    {
        Queue::fake();
        $superAdmin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        Sanctum::actingAs($superAdmin);
        $payload = $this->gymPayload(['create_login_account' => false]);
        $payload['owner'] = ['create_login_account' => false];
        $gymId = $this->postJson('/api/v1/gyms', $payload)->assertCreated()
            ->assertJsonPath('meta.owner_account', null)->json('data.id');

        $this->getJson("/api/v1/gyms/{$gymId}/owner-account", ['X-Gym-ID' => $gymId])
            ->assertOk()->assertJsonPath('data', null);
        $this->postJson("/api/v1/gyms/{$gymId}/owner-account", [
            'name' => 'Later Owner',
            'email' => 'later.owner@example.test',
            'phone' => '+92 300 1234567',
            'setup_method' => 'invite',
            'reason' => 'Owner appointed after tenant creation',
        ], ['X-Gym-ID' => $gymId])->assertCreated()
            ->assertJsonPath('data.email', 'later.owner@example.test');
    }

    /** @param array<string, mixed> $ownerOverrides */
    private function gymPayload(array $ownerOverrides = []): array
    {
        return [
            'name' => 'Owner Lifecycle Gym',
            'legal_name' => 'Owner Lifecycle Gym Limited',
            'base_currency' => 'GBP',
            'country_code' => 'GB',
            'timezone' => 'Europe/London',
            'owner' => array_merge([
                'create_login_account' => true,
                'name' => 'Gym Owner',
                'email' => 'owner@example.test',
                'phone' => '+44 7700 900001',
            ], $ownerOverrides),
        ];
    }

    /** @return array<string, string> */
    private function browserHeaders(): array
    {
        return ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'];
    }
}
