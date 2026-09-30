<?php

namespace Tests\Feature;

use App\Enums\BranchStatus;
use App\Enums\InvitationStatus;
use App\Enums\UserRole;
use App\Jobs\SendAccountInvitation;
use App\Models\Gym;
use App\Models\GymBranch;
use App\Models\StaffInvitation;
use App\Models\StaffProfile;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class StaffInvitationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_queue_receptionist_and_manager_invitations_with_branch_access(): void
    {
        Queue::fake();
        [$owner, $gym] = $this->tenant();
        $branch = $this->branch($gym);
        Sanctum::actingAs($owner);
        $headers = ['X-Gym-ID' => $gym->id];

        foreach ([
            ['email' => 'reception@example.test', 'role' => UserRole::Receptionist->value, 'employee_number' => 'INV-REC-01'],
            ['email' => 'manager@example.test', 'role' => UserRole::GymManager->value, 'employee_number' => 'INV-MGR-01'],
        ] as $input) {
            $this->postJson("/api/v1/gyms/{$gym->id}/staff-invitations", $input + [
                'home_branch_id' => $branch->id,
                'expires_in_days' => 7,
            ], $headers)
                ->assertCreated()
                ->assertJsonPath('data.status', InvitationStatus::Pending->value)
                ->assertJsonPath('data.home_branch_id', $branch->id)
                ->assertJsonPath('data.delivery.status', 'queued');
        }

        Queue::assertPushed(SendAccountInvitation::class, 2);
        Queue::assertPushed(SendAccountInvitation::class, fn (SendAccountInvitation $job): bool =>
            $job->email === 'reception@example.test'
            && $job->kind === 'staff'
            && $job->queue === 'notifications'
            && $job->eventType === 'staff_invitation'
        );
    }

    public function test_duplicate_email_employee_number_and_invalid_role_return_clear_validation_errors(): void
    {
        Queue::fake();
        [$owner, $gym] = $this->tenant();
        Sanctum::actingAs($owner);
        $headers = ['X-Gym-ID' => $gym->id];
        $base = [
            'email' => 'duplicate@example.test',
            'role' => UserRole::Receptionist->value,
            'employee_number' => 'INV-DUP-01',
        ];

        $this->postJson("/api/v1/gyms/{$gym->id}/staff-invitations", $base, $headers)->assertCreated();
        $this->postJson("/api/v1/gyms/{$gym->id}/staff-invitations", array_merge($base, ['employee_number' => 'INV-DUP-02']), $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson("/api/v1/gyms/{$gym->id}/staff-invitations", array_merge($base, ['email' => 'other@example.test']), $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('employee_number');
        $this->postJson("/api/v1/gyms/{$gym->id}/staff-invitations", array_merge($base, [
            'email' => 'invalid-role@example.test',
            'employee_number' => 'INV-DUP-03',
            'role' => UserRole::Member->value,
        ]), $headers)->assertUnprocessable()->assertJsonValidationErrors('role');
    }

    public function test_mail_transport_failure_keeps_pending_invitation_and_exposes_delivery_failure(): void
    {
        config(['queue.default' => 'sync']);
        Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('simulated transport failure'));
        [$owner, $gym] = $this->tenant();
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/v1/gyms/{$gym->id}/staff-invitations", [
            'email' => 'mail-failure@example.test',
            'role' => UserRole::Receptionist->value,
            'employee_number' => 'INV-MAIL-01',
        ], ['X-Gym-ID' => $gym->id])
            ->assertCreated()
            ->assertJsonPath('data.status', InvitationStatus::Pending->value)
            ->assertJsonPath('data.delivery.status', 'failed');

        app(TenantContext::class)->run($gym, function () use ($response): void {
            $invitation = StaffInvitation::query()->findOrFail($response->json('data.id'));
            $this->assertSame('failed', $invitation->metadata['delivery']['status']);
            $this->assertSame('The invitation email could not be queued.', $invitation->metadata['delivery']['failure_reason']);
        });
    }

    public function test_recipient_can_preview_and_activate_a_new_staff_account_and_expired_tokens_fail(): void
    {
        Queue::fake();
        [$owner, $gym] = $this->tenant();
        Sanctum::actingAs($owner);
        $created = $this->postJson("/api/v1/gyms/{$gym->id}/staff-invitations", [
            'email' => 'activate-staff@example.test',
            'role' => UserRole::Receptionist->value,
            'employee_number' => 'INV-ACT-01',
        ], ['X-Gym-ID' => $gym->id])->assertCreated();
        $token = $created->json('meta.acceptance_token');
        $invitationId = $created->json('data.id');

        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/gyms/{$gym->id}/staff-invitations/preview", ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.existing_account', false)
            ->assertJsonMissingPath('data.email');
        $this->withHeaders($this->browserHeaders())->postJson(
            "/api/v1/gyms/{$gym->id}/staff-invitations/accept",
            ['token' => $token, 'password' => 'a-secure-password', 'password_confirmation' => 'a-secure-password'],
        )->assertOk()->assertJsonPath('data.authentication', 'session');

        $user = User::query()->where('email', 'activate-staff@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        app(TenantContext::class)->run($gym, function () use ($gym, $user, $invitationId): void {
            $this->assertDatabaseHas('gym_user', [
                'gym_id' => $gym->id,
                'user_id' => $user->id,
                'role' => UserRole::Receptionist->value,
                'status' => 'active',
            ]);
            $this->assertDatabaseHas('staff_profiles', [
                'gym_id' => $gym->id,
                'user_id' => $user->id,
                'employee_number' => 'INV-ACT-01',
            ]);
            $this->assertSame(InvitationStatus::Accepted, StaffInvitation::query()->findOrFail($invitationId)->status);
        });

        Sanctum::actingAs($owner);
        $expired = $this->postJson("/api/v1/gyms/{$gym->id}/staff-invitations", [
            'email' => 'expired-staff@example.test',
            'role' => UserRole::Receptionist->value,
            'employee_number' => 'INV-ACT-02',
        ], ['X-Gym-ID' => $gym->id])->assertCreated();
        app(TenantContext::class)->run($gym, fn () => StaffInvitation::query()
            ->findOrFail($expired->json('data.id'))->update(['expires_at' => now()->subMinute()]));
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/gyms/{$gym->id}/staff-invitations/preview", [
            'token' => $expired->json('meta.acceptance_token'),
        ])->assertUnprocessable()->assertJsonValidationErrors('token');
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

    private function branch(Gym $gym): GymBranch
    {
        return app(TenantContext::class)->run($gym, fn () => GymBranch::query()->create([
            'name' => 'Invitation Branch',
            'code' => 'INVITE',
            'timezone' => 'Europe/London',
            'status' => BranchStatus::Active,
            'is_primary' => false,
        ]));
    }

    /** @return array<string, string> */
    private function browserHeaders(): array
    {
        return ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'];
    }
}