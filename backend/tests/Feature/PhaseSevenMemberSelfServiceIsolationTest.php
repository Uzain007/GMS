<?php

namespace Tests\Feature;

use App\Enums\MemberStatus;
use App\Enums\UserRole;
use App\Models\Gym;
use App\Models\Member;
use App\Models\MemberAccessCredential;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhaseSevenMemberSelfServiceIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_profile_is_linked_to_the_actor_and_excludes_internal_fields(): void
    {
        [$user, $gym, $member] = $this->memberTenant('PRIMARY');
        [, , $otherMember] = $this->memberTenant('OTHER');
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/gyms/{$gym->id}/member/me", ['X-Gym-ID' => $gym->id])
            ->assertOk()->assertJsonPath('data.member_number', $member->member_number)
            ->assertJsonPath('data.member_code', $member->member_code)
            ->assertJsonMissingPath('data.id')->assertJsonMissingPath('data.gym_id')
            ->assertJsonMissingPath('data.user_id')->assertJsonMissingPath('data.metadata');

        // Staff-owned fields are never validated or persisted by self-service.
        $this->patchJson("/api/v1/gyms/{$gym->id}/member/me", [
            'phone' => '+44 7700 900777', 'status' => MemberStatus::Cancelled->value,
            'member_id' => $otherMember->id,
        ], ['X-Gym-ID' => $gym->id])
            ->assertOk()->assertJsonPath('data.phone', '+44 7700 900777')
            ->assertJsonPath('data.status', MemberStatus::Active->value);

        app(TenantContext::class)->run($gym, function () use ($member): void {
            $fresh = $member->fresh();
            $this->assertSame(MemberStatus::Active, $fresh->status);
            $this->assertSame('+44 7700 900777', $fresh->phone);
        });
    }

    public function test_member_cannot_select_another_tenant_or_member_record(): void
    {
        [$user, $gym] = $this->memberTenant('ALLOWED');
        [, $blockedGym, $blockedMember] = $this->memberTenant('BLOCKED');
        Sanctum::actingAs($user);
        $this->getJson("/api/v1/gyms/{$blockedGym->id}/member/me", ['X-Gym-ID' => $blockedGym->id])->assertForbidden();
        $this->getJson("/api/v1/gyms/{$gym->id}/members/{$blockedMember->id}", ['X-Gym-ID' => $gym->id])->assertForbidden();
    }

    public function test_qr_plaintext_is_returned_once_and_only_its_digest_persists(): void
    {
        [$user, $gym, $member] = $this->memberTenant('PASS');
        Sanctum::actingAs($user);
        $plaintext = $this->postJson("/api/v1/gyms/{$gym->id}/member/access-credential", [], ['X-Gym-ID' => $gym->id])
            ->assertCreated()->assertJsonMissingPath('data.id')->assertJsonMissingPath('data.member_id')
            ->assertJsonMissingPath('data.gym_id')->json('data.credential');
        $this->assertIsString($plaintext);
        $this->assertStringStartsWith('icqr_', $plaintext);
        app(TenantContext::class)->run($gym, function () use ($member, $plaintext): void {
            $credential = MemberAccessCredential::query()->where('member_id', $member->id)->firstOrFail();
            $this->assertSame(hash('sha256', $plaintext), $credential->getRawOriginal('credential_hash'));
            $this->assertNotSame($plaintext, $credential->getRawOriginal('credential_hash'));
        });
        $this->getJson("/api/v1/gyms/{$gym->id}/member/access-credential", ['X-Gym-ID' => $gym->id])
            ->assertOk()->assertJsonMissingPath('data.credential')->assertJsonMissingPath('data.credential_hash');
    }

    /** @return array{User, Gym, Member} */
    private function memberTenant(string $suffix): array
    {
        $user = User::factory()->create();
        $gym = Gym::factory()->create();
        $member = app(TenantContext::class)->run($gym, function () use ($gym, $user, $suffix): Member {
            $gym->users()->attach($user, ['role' => UserRole::Member->value, 'status' => 'active']);
            return Member::query()->create([
                'user_id' => $user->id, 'member_number' => 'MBR-'.$suffix,
                'first_name' => 'Member', 'last_name' => $suffix,
                'email' => strtolower($suffix).'@example.test', 'status' => MemberStatus::Active,
                'joined_at' => now()->subMonth(),
            ]);
        });
        return [$user, $gym, $member];
    }
}
