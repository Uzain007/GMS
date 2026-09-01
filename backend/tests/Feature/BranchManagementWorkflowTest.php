<?php

namespace Tests\Feature;

use App\Enums\BranchStatus;
use App\Enums\MemberStatus;
use App\Enums\StaffStatus;
use App\Enums\UserRole;
use App\Models\Gym;
use App\Models\GymBranch;
use App\Models\Member;
use App\Models\StaffProfile;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BranchManagementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_manage_branch_assignments_and_schedule_with_an_assigned_trainer(): void
    {
        $owner = User::factory()->create();
        $trainerUser = User::factory()->create();
        $gym = Gym::factory()->create();
        $this->attachRole($gym, $owner, UserRole::GymOwner);
        $this->attachRole($gym, $trainerUser, UserRole::Trainer);
        Sanctum::actingAs($owner);

        $branch = $this->postJson("/api/v1/gyms/{$gym->id}/branches", [
            'name' => 'Riverside', 'code' => 'RIVER', 'timezone' => 'Europe/London',
        ], $this->headers($gym))->assertCreated()->json('data');

        [$member, $staff] = app(TenantContext::class)->run($gym, function () use ($trainerUser): array {
            return [
                Member::query()->create([
                    'member_number' => 'BRANCH-MEMBER-1', 'member_code' => '654321',
                    'first_name' => 'Branch', 'last_name' => 'Member',
                    'email' => 'branch-member@example.test', 'phone' => '+440000000001',
                    'status' => MemberStatus::Active,
                ]),
                StaffProfile::query()->create([
                    'user_id' => $trainerUser->id, 'employee_number' => 'TRAINER-BRANCH-1',
                    'status' => StaffStatus::Active,
                ]),
            ];
        });

        $this->patchJson("/api/v1/gyms/{$gym->id}/members/{$member->id}", [
            'home_branch_id' => $branch['id'], 'reason' => 'Assign member to new branch',
        ], $this->headers($gym))->assertOk()->assertJsonPath('data.home_branch_id', $branch['id']);

        $this->patchJson("/api/v1/gyms/{$gym->id}/staff/{$staff->id}", [
            'home_branch_id' => $branch['id'], 'reason' => 'Assign trainer to new branch',
        ], $this->headers($gym))->assertOk()->assertJsonPath('data.home_branch_id', $branch['id']);

        $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions", [
            'branch_id' => $branch['id'], 'trainer_staff_profile_id' => $staff->id,
            'title' => 'Branch Strength', 'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHour()->toIso8601String(), 'capacity' => 16,
            'waitlist_enabled' => true,
        ], $this->headers($gym))->assertCreated()
            ->assertJsonPath('data.branch_id', $branch['id'])
            ->assertJsonPath('data.trainer_staff_profile_id', $staff->id);
    }

    public function test_inactive_branch_blocks_new_classes_and_linked_branch_must_be_archived_not_deleted(): void
    {
        $owner = User::factory()->create();
        $gym = Gym::factory()->create();
        $this->attachRole($gym, $owner, UserRole::GymOwner);
        $branch = $this->branch($gym, 'LINKED');
        $member = app(TenantContext::class)->run($gym, fn () => Member::query()->create([
            'home_branch_id' => $branch->id, 'member_number' => 'LINKED-MEMBER-1',
            'member_code' => '654322', 'first_name' => 'Linked', 'last_name' => 'Member',
            'email' => 'linked-member@example.test', 'phone' => '+440000000002',
            'status' => MemberStatus::Active,
        ]));
        Sanctum::actingAs($owner);

        $this->patchJson("/api/v1/gyms/{$gym->id}/branches/{$branch->id}", [
            'status' => BranchStatus::Inactive->value, 'reason' => 'Archive branch safely',
        ], $this->headers($gym))->assertOk()->assertJsonPath('data.status', 'inactive');

        $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions", [
            'branch_id' => $branch->id, 'title' => 'Blocked class',
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHour()->toIso8601String(), 'capacity' => 10,
        ], $this->headers($gym))->assertUnprocessable()->assertJsonValidationErrors('branch_id');

        $this->postJson("/api/v1/gyms/{$gym->id}/attendance/check-ins", [
            'branch_id' => $branch->id, 'member_code' => $member->member_code,
        ], $this->headers($gym))->assertUnprocessable()->assertJsonValidationErrors('branch_id');

        $this->deleteJson("/api/v1/gyms/{$gym->id}/branches/{$branch->id}", [
            'reason' => 'Remove linked branch',
        ], $this->headers($gym))->assertUnprocessable()->assertJsonValidationErrors('branch');

        $this->assertNotNull($member->fresh());
    }

    public function test_only_empty_non_primary_branch_can_be_deleted_and_cross_tenant_id_is_hidden(): void
    {
        $owner = User::factory()->create();
        $gym = Gym::factory()->create();
        $otherGym = Gym::factory()->create();
        $this->attachRole($gym, $owner, UserRole::GymOwner);
        $branch = $this->branch($gym, 'EMPTY');
        $otherBranch = $this->branch($otherGym, 'OTHER');
        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/gyms/{$gym->id}/branches/{$otherBranch->id}", [
            'reason' => 'Attempt cross tenant removal',
        ], $this->headers($gym))->assertNotFound();

        $this->deleteJson("/api/v1/gyms/{$gym->id}/branches/{$branch->id}", [
            'reason' => 'Unused test branch cleanup',
        ], $this->headers($gym))->assertOk()->assertJsonPath('data.deleted', true);

        app(TenantContext::class)->run($gym, function () use ($branch): void {
            $this->assertDatabaseMissing('gym_branches', ['id' => $branch->id]);
            $this->assertDatabaseHas('audit_logs', [
                'gym_id' => $branch->gym_id, 'event' => 'branch.deleted',
                'auditable_id' => $branch->id, 'reason' => 'Unused test branch cleanup',
            ]);
        });
    }

    private function branch(Gym $gym, string $code): GymBranch
    {
        return app(TenantContext::class)->run($gym, fn () => GymBranch::query()->create([
            'name' => $code.' Branch', 'code' => $code, 'timezone' => 'Europe/London',
            'status' => BranchStatus::Active, 'is_primary' => false,
        ]));
    }

    private function attachRole(Gym $gym, User $user, UserRole $role): void
    {
        app(TenantContext::class)->run($gym, fn () => $gym->users()->attach($user, [
            'role' => $role->value, 'status' => 'active',
        ]));
    }

    /** @return array<string, string> */
    private function headers(Gym $gym): array
    {
        return ['X-Gym-ID' => $gym->id];
    }
}
