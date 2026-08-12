<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\GymStatus;
use App\Enums\MemberStatus;
use App\Enums\StaffStatus;
use App\Enums\UserRole;
use App\Models\Gym;
use App\Models\Member;
use App\Models\MembershipPlan;
use App\Models\StaffProfile;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

class PhaseThreeTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_routes_deny_another_gym_and_cross_tenant_record_ids(): void
    {
        $owner = User::factory()->create();
        $allowedGym = Gym::factory()->create();
        $blockedGym = Gym::factory()->create();
        $this->attachRole($allowedGym, $owner, UserRole::GymOwner, 'active');

        $allowedMember = $this->createMember($allowedGym, 'ALLOWED-1');
        $blockedMember = $this->createMember($blockedGym, 'BLOCKED-1');
        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/gyms/{$allowedGym->id}/members/{$allowedMember->id}", [
            'X-Gym-ID' => $allowedGym->id,
        ])->assertOk()->assertJsonPath('data.member_number', 'ALLOWED-1');

        // Middleware denies selecting a gym where the actor has no membership.
        $this->getJson("/api/v1/gyms/{$blockedGym->id}/members/{$blockedMember->id}", [
            'X-Gym-ID' => $blockedGym->id,
        ])->assertForbidden();

        // The scoped model returns 404 when another tenant ID is injected into an allowed route.
        $this->getJson("/api/v1/gyms/{$allowedGym->id}/members/{$blockedMember->id}", [
            'X-Gym-ID' => $allowedGym->id,
        ])->assertNotFound();
    }

    public function test_suspended_gym_membership_never_grants_tenant_access(): void
    {
        $user = User::factory()->create();
        $gym = Gym::factory()->create();
        $this->attachRole($gym, $user, UserRole::GymOwner, 'suspended');
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/gyms/{$gym->id}", ['X-Gym-ID' => $gym->id])
            ->assertForbidden();
    }

    public function test_tenant_models_fail_closed_without_context(): void
    {
        $gym = Gym::factory()->create();
        $member = $this->createMember($gym, 'CLOSED-1');

        // No context means ordinary model queries return zero rows, not all tenants.
        $this->assertSame(0, Member::query()->count());

        $this->expectException(LogicException::class);
        Member::query()->create([
            'gym_id' => $gym->id,
            'member_number' => 'UNSAFE-1',
            'first_name' => 'Unsafe',
            'last_name' => 'Write',
            'status' => MemberStatus::Lead,
        ]);
    }

    public function test_membership_creation_snapshots_plan_money_and_terms(): void
    {
        $owner = User::factory()->create();
        $gym = Gym::factory()->create();
        $this->attachRole($gym, $owner, UserRole::GymOwner, 'active');
        $member = $this->createMember($gym, 'SNAPSHOT-1');

        $plan = app(TenantContext::class)->run($gym, fn () => MembershipPlan::query()->create([
            'name' => 'Monthly Strength',
            'code' => 'MONTHLY-STRENGTH',
            'billing_interval' => 'monthly',
            'interval_count' => 1,
            'price_amount_minor' => 4999,
            'currency' => Currency::GBP,
            'joining_fee_minor' => 1000,
            'trial_days' => 0,
            'status' => 'active',
            'terms' => ['notice_days' => 30],
        ]));
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/gyms/{$gym->id}/memberships", [
            'member_id' => $member->id,
            'plan_id' => $plan->id,
            'starts_at' => '2026-08-10',
            'auto_renew' => true,
        ], ['X-Gym-ID' => $gym->id])
            ->assertCreated()
            ->assertJsonPath('data.price_amount_minor', 4999)
            ->assertJsonPath('data.currency', 'GBP')
            ->assertJsonPath('data.terms_snapshot.notice_days', 30);
    }

    public function test_manager_cannot_invite_an_owner_or_another_manager(): void
    {
        $manager = User::factory()->create();
        $gym = Gym::factory()->create();
        $this->attachRole($gym, $manager, UserRole::GymManager, 'active');
        Sanctum::actingAs($manager);

        $this->postJson("/api/v1/gyms/{$gym->id}/staff-invitations", [
            'email' => 'owner-candidate@example.com',
            'role' => UserRole::GymOwner->value,
            'employee_number' => 'STAFF-001',
        ], ['X-Gym-ID' => $gym->id])->assertForbidden();
    }

    public function test_manager_cannot_suspend_an_owner_without_sending_a_role_field(): void
    {
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $gym = Gym::factory()->create();
        $this->attachRole($gym, $owner, UserRole::GymOwner, 'active');
        $this->attachRole($gym, $manager, UserRole::GymManager, 'active');
        $profile = app(TenantContext::class)->run($gym, fn () => StaffProfile::query()->create([
            'user_id' => $owner->id,
            'employee_number' => 'OWNER-001',
            'job_title' => 'Owner',
            'status' => StaffStatus::Active,
        ]));
        Sanctum::actingAs($manager);

        // Omitting role must not bypass the hierarchy guard.
        $this->patchJson("/api/v1/gyms/{$gym->id}/staff/{$profile->id}", [
            'status' => StaffStatus::Suspended->value,
            'reason' => 'Attempted manager override',
        ], ['X-Gym-ID' => $gym->id])->assertForbidden();
    }

    private function createMember(Gym $gym, string $memberNumber): Member
    {
        return app(TenantContext::class)->run($gym, fn () => Member::query()->create([
            'member_number' => $memberNumber,
            'first_name' => 'Test',
            'last_name' => 'Member',
            'status' => MemberStatus::Active,
            'joined_at' => now(),
        ]));
    }

    private function attachRole(Gym $gym, User $user, UserRole $role, string $status): void
    {
        // This helper keeps the test valid against both SQLite and PostgreSQL RLS.
        app(TenantContext::class)->run($gym, fn () => $gym->users()->attach($user, [
            'role' => $role->value,
            'status' => $status,
        ]));
    }
}
