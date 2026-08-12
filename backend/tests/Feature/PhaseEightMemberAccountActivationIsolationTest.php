<?php

namespace Tests\Feature;

use App\Enums\InvitationStatus;
use App\Enums\MemberStatus;
use App\Enums\UserRole;
use App\Models\Gym;
use App\Models\Member;
use App\Models\MemberAccountInvitation;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhaseEightMemberAccountActivationIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_issue_one_secret_and_member_can_activate_a_new_account(): void
    {
        [$owner, $gym, $member] = $this->tenantMember('NEW');
        Sanctum::actingAs($owner);

        $token = $this->postJson(
            "/api/v1/gyms/{$gym->id}/members/{$member->id}/account-invitations",
            ['expires_in_hours' => 24],
            ['X-Gym-ID' => $gym->id],
        )->assertCreated()
            ->assertJsonMissingPath('data.token_hash')
            ->json('meta.activation_token');

        $this->assertIsString($token);
        $this->assertSame(64, strlen($token));
        app(TenantContext::class)->run($gym, function () use ($gym, $token): void {
            $invitation = MemberAccountInvitation::query()->firstOrFail();
            $this->assertSame(hash('sha256', strtolower($gym->id).'|'.$token), $invitation->getRawOriginal('token_hash'));
            $this->assertNotSame($token, $invitation->getRawOriginal('token_hash'));
        });

        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/gyms/{$gym->id}/member-account-invitations/preview", ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.gym_name', $gym->name)
            ->assertJsonPath('data.member_first_name', 'Member')
            ->assertJsonPath('data.existing_account', false)
            ->assertJsonMissingPath('data.email');

        $this->withHeaders($this->browserHeaders())->postJson(
            "/api/v1/gyms/{$gym->id}/member-account-invitations/accept",
            ['token' => $token, 'password' => 'a-secure-password', 'password_confirmation' => 'a-secure-password'],
        )->assertOk()
            ->assertJsonPath('data.authentication', 'session')
            ->assertJsonPath('data.user.gyms.0.role', UserRole::Member->value);

        $user = User::query()->where('email', 'new@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        app(TenantContext::class)->run($gym, function () use ($gym, $member, $user): void {
            $this->assertSame($user->id, $member->fresh()->user_id);
            $this->assertDatabaseHas('gym_user', [
                'gym_id' => $gym->id, 'user_id' => $user->id,
                'role' => UserRole::Member->value, 'status' => 'active',
            ]);
            $this->assertSame(InvitationStatus::Accepted, MemberAccountInvitation::query()->firstOrFail()->status);
        });
    }

    public function test_token_is_bound_to_one_tenant_and_reissue_revokes_the_old_secret(): void
    {
        [$owner, $gym, $member] = $this->tenantMember('BOUND');
        [, $otherGym] = $this->tenantMember('OTHER');
        Sanctum::actingAs($owner);

        $first = $this->postJson(
            "/api/v1/gyms/{$gym->id}/members/{$member->id}/account-invitations",
            [], ['X-Gym-ID' => $gym->id],
        )->assertCreated()->json('meta.activation_token');
        $second = $this->postJson(
            "/api/v1/gyms/{$gym->id}/members/{$member->id}/account-invitations",
            [], ['X-Gym-ID' => $gym->id],
        )->assertCreated()->json('meta.activation_token');

        $this->assertNotSame($first, $second);
        app(TenantContext::class)->run($gym, function (): void {
            $this->assertSame(1, MemberAccountInvitation::query()->where('status', InvitationStatus::Revoked->value)->count());
            $this->assertSame(1, MemberAccountInvitation::query()->where('status', InvitationStatus::Pending->value)->count());
        });

        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/gyms/{$otherGym->id}/member-account-invitations/preview", ['token' => $second])
            ->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->postJson("/api/v1/gyms/{$gym->id}/member-account-invitations/preview", ['token' => $first])
            ->assertUnprocessable()->assertJsonValidationErrors('token');
    }

    public function test_existing_account_is_linked_without_changing_its_password_or_staff_role(): void
    {
        [$owner, $gym, $member] = $this->tenantMember('EXISTING');
        $existing = User::factory()->create(['email' => 'existing@example.test']);
        $originalPassword = $existing->getRawOriginal('password');
        Sanctum::actingAs($owner);
        $token = $this->postJson(
            "/api/v1/gyms/{$gym->id}/members/{$member->id}/account-invitations",
            [], ['X-Gym-ID' => $gym->id],
        )->assertCreated()->json('meta.activation_token');

        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->browserHeaders())->postJson(
            "/api/v1/gyms/{$gym->id}/member-account-invitations/accept",
            ['token' => $token],
        )->assertOk();
        $this->assertSame($originalPassword, $existing->fresh()->getRawOriginal('password'));

        [$staffOwner, $staffGym, $staffMember] = $this->tenantMember('STAFF');
        $staff = User::factory()->create(['email' => 'staff@example.test']);
        app(TenantContext::class)->run($staffGym, fn () => $staffGym->users()->attach($staff, [
            'role' => UserRole::Receptionist->value, 'status' => 'active',
        ]));
        Sanctum::actingAs($staffOwner);
        $staffToken = $this->postJson(
            "/api/v1/gyms/{$staffGym->id}/members/{$staffMember->id}/account-invitations",
            [], ['X-Gym-ID' => $staffGym->id],
        )->assertCreated()->json('meta.activation_token');
        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->browserHeaders())->postJson(
            "/api/v1/gyms/{$staffGym->id}/member-account-invitations/accept",
            ['token' => $staffToken],
        )->assertUnprocessable()->assertJsonValidationErrors('token');
        app(TenantContext::class)->run($staffGym, fn () => $this->assertDatabaseHas('gym_user', [
            'gym_id' => $staffGym->id, 'user_id' => $staff->id,
            'role' => UserRole::Receptionist->value,
        ]));
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

    /** @return array<string, string> */
    private function browserHeaders(): array
    {
        return ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'];
    }
}
