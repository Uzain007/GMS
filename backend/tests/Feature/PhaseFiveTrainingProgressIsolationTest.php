<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\MemberStatus;
use App\Enums\StaffStatus;
use App\Enums\UserRole;
use App\Models\Gym;
use App\Models\Member;
use App\Models\StaffProfile;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhaseFiveTrainingProgressIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cross_tenant_member_and_plan_ids_are_not_accessible(): void
    {
        [$owner, $gym] = $this->tenant();
        [$otherOwner, $otherGym] = $this->tenant();
        [, $otherMember] = $this->trainerAndMember($otherGym);

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/gyms/{$gym->id}/trainer-assignments", [
            'trainer_staff_profile_id' => $otherMember->id,
            'member_id' => $otherMember->id,
            'starts_on' => today()->toDateString(),
        ], ['X-Gym-ID' => $gym->id])->assertUnprocessable();

        // Create a valid record under the second tenant, then prove the first
        // tenant's global scope and route/header context make it undiscoverable.
        [$otherTrainer, $secondMember] = $this->trainerAndMember($otherGym, 'SECOND');
        Queue::fake();
        Sanctum::actingAs($otherOwner);
        $this->postJson("/api/v1/gyms/{$otherGym->id}/trainer-assignments", [
            'trainer_staff_profile_id' => $otherTrainer->id,
            'member_id' => $secondMember->id,
            'starts_on' => today()->toDateString(),
        ], ['X-Gym-ID' => $otherGym->id])->assertCreated();
        $plan = $this->postJson("/api/v1/gyms/{$otherGym->id}/workout-plans", $this->planPayload($otherTrainer, $secondMember), [
            'X-Gym-ID' => $otherGym->id,
        ])->assertCreated()->json('data');

        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/gyms/{$gym->id}/workout-plans/{$plan['id']}", [
            'X-Gym-ID' => $gym->id,
        ])->assertNotFound();
    }

    public function test_trainer_requires_active_assignment_and_history_keeps_exact_integer_values(): void
    {
        [$owner, $gym] = $this->tenant();
        [$trainer, $assignedMember, $trainerUser] = $this->trainerAndMember($gym);
        [, $unassignedMember] = $this->trainerAndMember($gym, 'UNASSIGNED', false);
        Queue::fake();

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/gyms/{$gym->id}/trainer-assignments", [
            'trainer_staff_profile_id' => $trainer->id,
            'member_id' => $assignedMember->id,
            'starts_on' => today()->toDateString(),
        ], ['X-Gym-ID' => $gym->id])->assertCreated();

        Sanctum::actingAs($trainerUser);
        $this->postJson("/api/v1/gyms/{$gym->id}/progress-measurements", [
            'member_id' => $unassignedMember->id,
            'metric' => 'body_weight', 'value_milli' => 81250, 'unit' => 'kg',
            'measured_at' => now()->toIso8601String(),
        ], ['X-Gym-ID' => $gym->id])->assertForbidden();

        $progress = $this->postJson("/api/v1/gyms/{$gym->id}/progress-measurements", [
            'member_id' => $assignedMember->id,
            'metric' => 'body_weight', 'value_milli' => 81250, 'unit' => 'kg',
            'measured_at' => now()->toIso8601String(),
        ], ['X-Gym-ID' => $gym->id])->assertCreated()->assertJsonPath('data.value_milli', 81250);
        $this->assertSame($assignedMember->id, $progress->json('data.member_id'));

        $plan = $this->postJson("/api/v1/gyms/{$gym->id}/workout-plans", $this->planPayload($trainer, $assignedMember), [
            'X-Gym-ID' => $gym->id,
        ])->assertCreated()->assertJsonPath('data.exercises.0.target_load_grams', 52500)->json('data');

        $this->postJson("/api/v1/gyms/{$gym->id}/workout-sessions", [
            'workout_plan_id' => $plan['id'],
            'member_id' => $assignedMember->id,
            'performed_at' => now()->subMinute()->toIso8601String(),
            'duration_seconds' => 2700,
            'sets' => [[
                'workout_plan_exercise_id' => $plan['exercises'][0]['id'],
                'set_number' => 1, 'reps' => 8, 'load_grams' => 51750, 'rpe' => 7,
            ]],
        ], ['X-Gym-ID' => $gym->id])
            ->assertCreated()
            ->assertJsonPath('data.sets.0.load_grams', 51750);

        $assignment = app(TenantContext::class)->run($gym, fn () => $trainer->memberAssignments()->firstOrFail());
        Sanctum::actingAs($owner);
        $this->patchJson("/api/v1/gyms/{$gym->id}/trainer-assignments/{$assignment->id}/end", [
            'reason' => 'Trainer engagement completed',
        ], ['X-Gym-ID' => $gym->id])->assertSuccessful()->assertJsonPath('data.status', 'inactive');

        Sanctum::actingAs($trainerUser);
        $this->getJson("/api/v1/gyms/{$gym->id}/workout-plans/{$plan['id']}", [
            'X-Gym-ID' => $gym->id,
        ])->assertForbidden();

        app(TenantContext::class)->run($gym, fn () => $trainer->update(['status' => StaffStatus::Suspended]));
        $this->getJson("/api/v1/gyms/{$gym->id}/workout-plans", [
            'X-Gym-ID' => $gym->id,
        ])->assertForbidden();
    }

    /** @return array{User, Gym} */
    private function tenant(): array
    {
        $owner = User::factory()->create();
        $gym = Gym::factory()->create(['base_currency' => Currency::GBP]);
        app(TenantContext::class)->run($gym, function () use ($gym, $owner): void {
            $gym->users()->attach($owner, ['role' => UserRole::GymOwner->value, 'status' => 'active']);
        });
        return [$owner, $gym];
    }

    /** @return array{StaffProfile, Member, User} */
    private function trainerAndMember(Gym $gym, string $suffix = 'PRIMARY', bool $createTrainer = true): array
    {
        $trainerUser = User::factory()->create();
        return app(TenantContext::class)->run($gym, function () use ($gym, $trainerUser, $suffix, $createTrainer): array {
            $gym->users()->attach($trainerUser, ['role' => UserRole::Trainer->value, 'status' => 'active']);
            $trainer = $createTrainer
                ? StaffProfile::query()->create([
                    'user_id' => $trainerUser->id, 'employee_number' => 'TRN-'.$suffix,
                    'job_title' => 'Coach', 'status' => StaffStatus::Active,
                ])
                : StaffProfile::query()->firstOrFail();
            $member = Member::query()->create([
                'member_number' => 'MBR-'.$suffix, 'first_name' => 'Member',
                'last_name' => $suffix, 'email' => strtolower($suffix).'@example.test',
                'status' => MemberStatus::Active,
            ]);
            return [$trainer, $member, $trainerUser];
        });
    }

    /** @return array<string, mixed> */
    private function planPayload(StaffProfile $trainer, Member $member): array
    {
        return [
            'member_id' => $member->id, 'trainer_staff_profile_id' => $trainer->id,
            'title' => 'Strength foundation', 'starts_on' => today()->toDateString(), 'status' => 'active',
            'exercises' => [[
                'name' => 'Back squat', 'day_number' => 1, 'sort_order' => 1,
                'target_sets' => 3, 'target_reps_min' => 8, 'target_reps_max' => 8,
                'target_load_grams' => 52500, 'rest_seconds' => 90,
            ]],
        ];
    }
}
