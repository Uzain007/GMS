<?php

namespace Tests\Feature;

use App\Enums\MemberStatus;
use App\Enums\UserRole;
use App\Jobs\GenerateMemberDataExport;
use App\Models\Gym;
use App\Models\Member;
use App\Models\MemberDataExport;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhaseTwelveMemberDataExportIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_queues_only_the_selected_tenants_member_export(): void
    {
        Queue::fake();
        [$owner, $gym, $member] = $this->tenantMember('EXPORT');
        [, $otherGym, $otherMember] = $this->tenantMember('OTHER');
        Sanctum::actingAs($owner);

        $exportId = $this->postJson(
            "/api/v1/gyms/{$gym->id}/members/{$member->id}/data-exports",
            [],
            ['X-Gym-ID' => $gym->id],
        )->assertAccepted()->assertJsonPath('data.member_id', $member->id)->json('data.id');

        Queue::assertPushed(GenerateMemberDataExport::class, fn ($job) => $job->gymId === $gym->id && $job->exportId === $exportId);
        app(TenantContext::class)->run($gym, fn () => $this->assertSame(1, MemberDataExport::query()->count()));

        $this->postJson(
            "/api/v1/gyms/{$gym->id}/members/{$otherMember->id}/data-exports",
            [],
            ['X-Gym-ID' => $gym->id],
        )->assertNotFound();
        app(TenantContext::class)->run($otherGym, fn () => $this->assertSame(0, MemberDataExport::query()->count()));
    }

    public function test_member_self_request_ignores_client_member_identity_and_receptionist_is_denied(): void
    {
        Queue::fake();
        [$owner, $gym, $member] = $this->tenantMember('SELF');
        $memberUser = User::factory()->create();
        $receptionist = User::factory()->create();
        app(TenantContext::class)->run($gym, function () use ($gym, $member, $memberUser, $receptionist): void {
            $member->update(['user_id' => $memberUser->id]);
            $gym->users()->attach($memberUser, ['role' => UserRole::Member->value, 'status' => 'active']);
            $gym->users()->attach($receptionist, ['role' => UserRole::Receptionist->value, 'status' => 'active']);
        });

        Sanctum::actingAs($memberUser);
        $this->postJson("/api/v1/gyms/{$gym->id}/member/data-exports", [], ['X-Gym-ID' => $gym->id])
            ->assertAccepted()->assertJsonPath('data.member_id', $member->id);

        Sanctum::actingAs($receptionist);
        $this->postJson(
            "/api/v1/gyms/{$gym->id}/members/{$member->id}/data-exports",
            [],
            ['X-Gym-ID' => $gym->id],
        )->assertForbidden();
    }

    /** @return array{User, Gym, Member} */
    private function tenantMember(string $suffix): array
    {
        $owner = User::factory()->create();
        $gym = Gym::factory()->create();
        $member = app(TenantContext::class)->run($gym, function () use ($gym, $owner, $suffix): Member {
            $gym->users()->attach($owner, ['role' => UserRole::GymOwner->value, 'status' => 'active']);
            return Member::query()->create([
                'member_number' => 'MBR-'.$suffix,
                'first_name' => 'Member', 'last_name' => $suffix,
                'email' => strtolower($suffix).'@example.test',
                'status' => MemberStatus::Active, 'joined_at' => now(),
            ]);
        });

        return [$owner, $gym, $member];
    }
}
