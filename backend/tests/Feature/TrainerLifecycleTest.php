<?php

namespace Tests\Feature;

use App\Enums\BranchStatus;
use App\Enums\MemberStatus;
use App\Enums\UserRole;
use App\Models\Gym;
use App\Models\GymBranch;
use App\Models\Member;
use App\Models\StaffProfile;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrainerLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_complete_the_trainer_class_coaching_plan_and_portal_journey(): void
    {
        [$owner, $gym] = $this->tenant();
        $branch = $this->branch($gym, 'CENTRAL');
        $member = $this->member($gym, $branch, 'JOURNEY');
        config(['filesystems.default' => 'local']);
        Storage::fake('local');
        Queue::fake();
        Sanctum::actingAs($owner);

        $created = $this->post("/api/v1/gyms/{$gym->id}/staff", [
            'name' => 'Jordan Trainer',
            'email' => 'jordan.trainer@example.test',
            'phone' => '+44 7700 900321',
            'home_branch_id' => $branch->id,
            'status' => 'active',
            'profile_image' => UploadedFile::fake()->createWithContent(
                'jordan.png',
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
            ),
        ], ['X-Gym-ID' => $gym->id, 'Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.role', 'trainer')
            ->assertJsonPath('data.user.name', 'Jordan Trainer')
            ->assertJsonPath('data.phone', '+44 7700 900321')
            ->assertJsonPath('data.home_branch_id', $branch->id)
            ->assertJsonPath('data.has_profile_image', true)
            ->assertJsonPath('meta.existing_account', false);

        $trainerId = $created->json('data.id');
        $setupToken = $created->json('meta.account_setup_token');
        $this->assertIsString($setupToken);
        $this->assertNotSame('', $setupToken);
        $trainer = app(TenantContext::class)->run($gym, fn () => StaffProfile::query()->with('user')->findOrFail($trainerId));
        app(TenantContext::class)->run($gym, function () use ($gym, $trainer, $trainerId, $branch): void {
            // Forced RLS must remain active while verifying both tenant-owned
            // trainer relationships on PostgreSQL.
            $this->assertDatabaseHas('gym_user', [
                'gym_id' => $gym->id,
                'user_id' => $trainer->user_id,
                'role' => 'trainer',
                'status' => 'active',
            ]);
            $this->assertDatabaseHas('staff_profile_branch', [
                'gym_id' => $gym->id,
                'staff_profile_id' => $trainerId,
                'branch_id' => $branch->id,
                'is_primary' => true,
            ]);
        });
        Storage::disk('local')->assertExists($trainer->profile_image_path);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $trainer->user->email, 'token' => $setupToken]);

        $starts = now()->addDay()->startOfHour();
        $session = $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions", [
            'branch_id' => $branch->id,
            'trainer_staff_profile_id' => $trainerId,
            'title' => 'Strength Foundations',
            'starts_at' => $starts->toIso8601String(),
            'ends_at' => $starts->addHour()->toIso8601String(),
            'capacity' => 12,
            'waitlist_enabled' => true,
        ], ['X-Gym-ID' => $gym->id])
            ->assertCreated()
            ->assertJsonPath('data.trainer.name', 'Jordan Trainer')
            ->json('data');

        $assignment = $this->postJson("/api/v1/gyms/{$gym->id}/trainer-assignments", [
            'trainer_staff_profile_id' => $trainerId,
            'member_id' => $member->id,
            'starts_on' => today()->toDateString(),
            'notes' => 'Two coached sessions per week',
        ], ['X-Gym-ID' => $gym->id])
            ->assertCreated()
            ->assertJsonPath('data.trainer.name', 'Jordan Trainer')
            ->json('data');

        $plan = $this->postJson("/api/v1/gyms/{$gym->id}/workout-plans", [
            'member_id' => $member->id,
            'trainer_staff_profile_id' => $trainerId,
            'title' => 'Eight-week strength plan',
            'starts_on' => today()->toDateString(),
            'status' => 'active',
            'exercises' => [[
                'name' => 'Goblet squat',
                'day_number' => 1,
                'sort_order' => 1,
                'target_sets' => 3,
                'target_reps_min' => 8,
                'target_reps_max' => 10,
                'rest_seconds' => 90,
            ]],
        ], ['X-Gym-ID' => $gym->id])
            ->assertCreated()
            ->assertJsonPath('data.trainer.name', 'Jordan Trainer')
            ->json('data');

        Sanctum::actingAs($trainer->user);
        $this->getJson("/api/v1/gyms/{$gym->id}/class-sessions", ['X-Gym-ID' => $gym->id])
            ->assertOk()->assertJsonFragment(['id' => $session['id']]);
        $this->getJson("/api/v1/gyms/{$gym->id}/trainer-assignments", ['X-Gym-ID' => $gym->id])
            ->assertOk()->assertJsonFragment(['id' => $assignment['id']]);
        $this->getJson("/api/v1/gyms/{$gym->id}/workout-plans", ['X-Gym-ID' => $gym->id])
            ->assertOk()->assertJsonFragment(['id' => $plan['id']]);
    }

    public function test_trainer_creation_and_consumers_are_tenant_and_branch_isolated(): void
    {
        [$owner, $gym] = $this->tenant();
        [$otherOwner, $otherGym] = $this->tenant();
        $branch = $this->branch($gym, 'HOME');
        $otherBranch = $this->branch($otherGym, 'OTHER');
        $secondBranch = $this->branch($gym, 'SECOND');
        $memberAtSecond = $this->member($gym, $secondBranch, 'SECOND');
        Sanctum::actingAs($owner);

        $wrongBranch = $this->postJson("/api/v1/gyms/{$gym->id}/staff", [
            'name' => 'Wrong Branch Trainer',
            'email' => 'wrong-branch@example.test',
            'phone' => '+44 7700 900322',
            'home_branch_id' => $otherBranch->id,
            'status' => 'active',
        ], ['X-Gym-ID' => $gym->id])->assertUnprocessable();
        $this->assertArrayHasKey('home_branch_id', $wrongBranch->json('errors'));

        $trainerId = $this->postJson("/api/v1/gyms/{$gym->id}/staff", [
            'name' => 'Tenant Trainer',
            'email' => 'tenant-trainer@example.test',
            'phone' => '+44 7700 900323',
            'home_branch_id' => $branch->id,
            'status' => 'active',
        ], ['X-Gym-ID' => $gym->id])->assertCreated()->json('data.id');

        $starts = now()->addDay();
        $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions", [
            'branch_id' => $secondBranch->id,
            'trainer_staff_profile_id' => $trainerId,
            'title' => 'Wrong branch class',
            'starts_at' => $starts->toIso8601String(),
            'ends_at' => $starts->addHour()->toIso8601String(),
            'capacity' => 8,
        ], ['X-Gym-ID' => $gym->id])->assertUnprocessable()
            ->assertJsonValidationErrors('trainer_staff_profile_id');

        $this->postJson("/api/v1/gyms/{$gym->id}/trainer-assignments", [
            'trainer_staff_profile_id' => $trainerId,
            'member_id' => $memberAtSecond->id,
            'starts_on' => today()->toDateString(),
        ], ['X-Gym-ID' => $gym->id])->assertUnprocessable()
            ->assertJsonValidationErrors('trainer_staff_profile_id');

        Sanctum::actingAs($otherOwner);
        $this->getJson("/api/v1/gyms/{$otherGym->id}/staff/{$trainerId}", ['X-Gym-ID' => $otherGym->id])
            ->assertNotFound();
        $this->getJson("/api/v1/gyms/{$otherGym->id}/staff", ['X-Gym-ID' => $otherGym->id])
            ->assertOk()->assertJsonMissing(['id' => $trainerId]);

        $trainerUser = User::query()->where('email', 'tenant-trainer@example.test')->firstOrFail();
        Sanctum::actingAs($trainerUser);
        $this->getJson("/api/v1/gyms/{$otherGym->id}/class-sessions", ['X-Gym-ID' => $otherGym->id])
            ->assertForbidden();
    }

    public function test_deactivation_hides_training_access_and_delete_preserves_referenced_history(): void
    {
        [$owner, $gym] = $this->tenant();
        $branch = $this->branch($gym, 'LIFECYCLE');
        $member = $this->member($gym, $branch, 'LIFECYCLE');
        Sanctum::actingAs($owner);

        $trainerId = $this->postJson("/api/v1/gyms/{$gym->id}/staff", [
            'name' => 'Lifecycle Trainer',
            'email' => 'lifecycle-trainer@example.test',
            'phone' => '+44 7700 900324',
            'home_branch_id' => $branch->id,
            'status' => 'active',
        ], ['X-Gym-ID' => $gym->id])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/gyms/{$gym->id}/staff/{$trainerId}", [
            'display_name' => 'Lifecycle Coach',
            'contact_email' => 'coach@example.test',
            'phone' => '+44 7700 900325',
            'status' => 'inactive',
            'reason' => 'Trainer is temporarily unavailable',
        ], ['X-Gym-ID' => $gym->id])
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Lifecycle Coach')
            ->assertJsonPath('data.status', 'inactive');

        $this->postJson("/api/v1/gyms/{$gym->id}/trainer-assignments", [
            'trainer_staff_profile_id' => $trainerId,
            'member_id' => $member->id,
            'starts_on' => today()->toDateString(),
        ], ['X-Gym-ID' => $gym->id])->assertUnprocessable();

        $this->patchJson("/api/v1/gyms/{$gym->id}/staff/{$trainerId}", [
            'status' => 'active',
            'reason' => 'Trainer returned to work',
        ], ['X-Gym-ID' => $gym->id])->assertOk();
        $this->postJson("/api/v1/gyms/{$gym->id}/trainer-assignments", [
            'trainer_staff_profile_id' => $trainerId,
            'member_id' => $member->id,
            'starts_on' => today()->toDateString(),
        ], ['X-Gym-ID' => $gym->id])->assertCreated();

        $this->deleteJson("/api/v1/gyms/{$gym->id}/staff/{$trainerId}", [
            'reason' => 'Attempting hard deletion with coaching history',
        ], ['X-Gym-ID' => $gym->id])->assertUnprocessable()
            ->assertJsonValidationErrors('staff');

        $unreferencedId = $this->postJson("/api/v1/gyms/{$gym->id}/staff", [
            'name' => 'Temporary Trainer',
            'email' => 'temporary-trainer@example.test',
            'phone' => '+44 7700 900326',
            'home_branch_id' => $branch->id,
            'status' => 'inactive',
        ], ['X-Gym-ID' => $gym->id])->assertCreated()->json('data.id');
        $userId = app(TenantContext::class)->run($gym, fn () => StaffProfile::query()->findOrFail($unreferencedId)->user_id);

        $this->deleteJson("/api/v1/gyms/{$gym->id}/staff/{$unreferencedId}", [
            'reason' => 'Duplicate profile created in error',
        ], ['X-Gym-ID' => $gym->id])->assertNoContent();
        $this->assertDatabaseMissing('staff_profiles', ['gym_id' => $gym->id, 'id' => $unreferencedId]);
        $this->assertDatabaseMissing('gym_user', ['gym_id' => $gym->id, 'user_id' => $userId]);
        $this->assertDatabaseHas('users', ['id' => $userId]);
    }

    /** @return array{User, Gym} */
    private function tenant(): array
    {
        $owner = User::factory()->create();
        $gym = Gym::factory()->create();
        app(TenantContext::class)->run($gym, fn () => $gym->users()->attach($owner, [
            'role' => UserRole::GymOwner->value,
            'status' => 'active',
        ]));
        return [$owner, $gym];
    }

    private function branch(Gym $gym, string $code): GymBranch
    {
        return app(TenantContext::class)->run($gym, fn () => GymBranch::query()->create([
            'name' => ucfirst(strtolower($code)).' Branch',
            'code' => $code,
            'timezone' => 'Europe/London',
            'status' => BranchStatus::Active,
            'is_primary' => false,
        ]));
    }

    private function member(Gym $gym, GymBranch $branch, string $suffix): Member
    {
        return app(TenantContext::class)->run($gym, fn () => Member::query()->create([
            'home_branch_id' => $branch->id,
            'member_number' => 'MBR-'.$suffix,
            'first_name' => 'Test',
            'last_name' => 'Member',
            'email' => strtolower($suffix).'@example.test',
            'status' => MemberStatus::Active,
        ]));
    }
}
