<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\MemberStatus;
use App\Enums\UserRole;
use App\Models\Gym;
use App\Models\GymBranch;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MembershipLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_member_membership_payment_qr_and_expiry_share_one_tenant_contract(): void
    {
        [$owner, $gym, $branch] = $this->tenant('LIFECYCLE');
        Sanctum::actingAs($owner);
        $headers = ['X-Gym-ID' => $gym->id];

        $plan = $this->postJson("/api/v1/gyms/{$gym->id}/membership-plans", [
            'branch_id' => $branch->id,
            'name' => '90 Day Local Membership',
            'code' => 'LOCAL-90-DAY',
            'description' => 'Safe lifecycle acceptance plan.',
            'billing_interval' => 'monthly',
            'interval_count' => 1,
            'price_amount_minor' => 4999,
            'joining_fee_minor' => 1000,
            'currency' => Currency::GBP->value,
            'duration_days' => 90,
            'trial_days' => 0,
            'status' => 'active',
        ], $headers)->assertCreated()
            ->assertJsonPath('data.duration_days', 90)
            ->json('data');

        $this->getJson("/api/v1/gyms/{$gym->id}/membership-plans", $headers)
            ->assertOk()
            ->assertJsonFragment(['id' => $plan['id'], 'name' => '90 Day Local Membership']);

        $member = $this->postJson("/api/v1/gyms/{$gym->id}/members", [
            'home_branch_id' => $branch->id,
            'first_name' => 'Mina',
            'last_name' => 'Carter',
            'email' => 'mina.lifecycle@example.test',
            'phone' => '+44 7700 900501',
            'status' => MemberStatus::Active->value,
        ], $headers)->assertCreated()->json('data');

        $startsAt = today()->subDays(40);
        $membership = $this->postJson("/api/v1/gyms/{$gym->id}/memberships", [
            'member_id' => $member['id'],
            'plan_id' => $plan['id'],
            'branch_id' => $branch->id,
            'starts_at' => $startsAt->toDateString(),
            'status' => 'active',
            'auto_renew' => true,
        ], $headers)->assertCreated()
            ->assertJsonPath('data.price_amount_minor', 4999)
            ->assertJsonPath('data.joining_fee_minor', 1000)
            ->assertJsonPath('data.ends_at', $startsAt->addDays(90)->toDateString())
            ->assertJsonPath('data.is_in_date', true)
            ->json('data');

        $this->getJson("/api/v1/gyms/{$gym->id}/members/{$member['id']}", $headers)
            ->assertOk()
            ->assertJsonPath('data.current_membership.id', $membership['id'])
            ->assertJsonPath('data.current_membership.plan.name', '90 Day Local Membership')
            ->assertJsonPath('data.current_membership.branch.id', $branch->id)
            ->assertJsonPath('data.current_membership.status', 'active')
            ->assertJsonPath('data.current_membership.is_in_date', true);

        $invoice = $this->postJson("/api/v1/gyms/{$gym->id}/invoices", [
            'member_id' => $member['id'],
            'membership_id' => $membership['id'],
            'branch_id' => $branch->id,
            'currency' => Currency::GBP->value,
            'items' => [[
                'description' => '90 Day Local Membership',
                'quantity' => 1,
                'unit_amount_minor' => 5999,
            ]],
        ], $headers)->assertCreated()
            ->assertJsonPath('data.membership_id', $membership['id'])
            ->json('data');

        $payment = $this->postJson("/api/v1/gyms/{$gym->id}/payments", [
            'member_id' => $member['id'],
            'membership_id' => $membership['id'],
            'invoice_id' => $invoice['id'],
            'branch_id' => $branch->id,
            'method' => 'cash',
            'amount_minor' => 5999,
            'currency' => Currency::GBP->value,
            'idempotency_key' => 'membership-lifecycle-cash',
        ], $headers)->assertCreated()
            ->assertJsonPath('data.membership_id', $membership['id'])
            ->assertJsonPath('data.status', 'paid')
            ->json('data');

        app(TenantContext::class)->run($gym, function () use ($payment, $membership): void {
            $saved = Payment::query()->findOrFail($payment['id']);
            $this->assertSame($membership['id'], $saved->membership_id);
            $this->assertSame(0, $saved->invoice->due_amount_minor);
        });

        $credential = $this->postJson("/api/v1/gyms/{$gym->id}/members/{$member['id']}/access-credential", [], $headers)
            ->assertCreated()->json('data.credential');
        $attendance = $this->postJson("/api/v1/gyms/{$gym->id}/attendance/check-ins", [
            'branch_id' => $branch->id,
            'credential' => $credential,
        ], $headers)->assertCreated()
            ->assertJsonPath('data.membership_id', $membership['id'])
            ->assertJsonPath('data.method', 'qr')
            ->json('data');
        $this->postJson("/api/v1/gyms/{$gym->id}/attendance/{$attendance['id']}/check-out", [], $headers)
            ->assertOk();

        $this->patchJson("/api/v1/gyms/{$gym->id}/memberships/{$membership['id']}", [
            'status' => 'active',
            'ends_at' => today()->subDay()->toDateString(),
            'next_billing_at' => null,
            'auto_renew' => false,
            'reason' => 'Local lifecycle expiry verification',
        ], $headers)->assertOk()->assertJsonPath('data.is_in_date', false);

        $this->postJson("/api/v1/gyms/{$gym->id}/attendance/check-ins", [
            'branch_id' => $branch->id,
            'credential' => $credential,
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('membership');
        $this->postJson("/api/v1/gyms/{$gym->id}/members/{$member['id']}/access-credential", [], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('membership');
    }

    public function test_member_profile_and_membership_inputs_cannot_cross_tenants(): void
    {
        [$owner, $gym] = $this->tenant('ALLOWED');
        [, $otherGym, $otherBranch] = $this->tenant('BLOCKED');
        $otherMember = app(TenantContext::class)->run($otherGym, fn () => Member::query()->create([
            'home_branch_id' => $otherBranch->id,
            'member_number' => 'MBR-BLOCKED',
            'first_name' => 'Blocked',
            'last_name' => 'Member',
            'status' => MemberStatus::Active,
        ]));

        Sanctum::actingAs($owner);
        $headers = ['X-Gym-ID' => $gym->id];
        $this->getJson("/api/v1/gyms/{$gym->id}/members/{$otherMember->id}", $headers)->assertNotFound();
        $this->postJson("/api/v1/gyms/{$gym->id}/members/{$otherMember->id}/access-credential", [], $headers)
            ->assertNotFound();
    }

    public function test_member_contact_values_are_required_and_survive_view_edit_save_and_reload(): void
    {
        [$owner, $gym, $branch] = $this->tenant('CONTACTS');
        Sanctum::actingAs($owner);
        $headers = ['X-Gym-ID' => $gym->id];

        $this->postJson("/api/v1/gyms/{$gym->id}/members", [
            'first_name' => 'Missing', 'last_name' => 'Contacts',
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors(['email', 'phone']);

        $member = $this->postJson("/api/v1/gyms/{$gym->id}/members", [
            'home_branch_id' => $branch->id,
            'first_name' => 'Contact', 'last_name' => 'Keeper',
            'email' => 'keeper@example.test', 'phone' => '+44 7700 900808',
        ], $headers)->assertCreated()->json('data');

        $this->getJson("/api/v1/gyms/{$gym->id}/members/{$member['id']}", $headers)
            ->assertOk()->assertJsonPath('data.email', 'keeper@example.test')
            ->assertJsonPath('data.phone', '+44 7700 900808');

        // Omitted contact keys must preserve the existing values, while an
        // explicit blank/null contact is rejected by the backend.
        $this->patchJson("/api/v1/gyms/{$gym->id}/members/{$member['id']}", [
            'first_name' => 'Updated', 'reason' => 'Verify contact persistence',
        ], $headers)->assertOk()->assertJsonPath('data.email', 'keeper@example.test')
            ->assertJsonPath('data.phone', '+44 7700 900808');
        $this->patchJson("/api/v1/gyms/{$gym->id}/members/{$member['id']}", [
            'email' => null, 'phone' => '', 'reason' => 'Invalid contact removal',
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors(['email', 'phone']);

        $this->getJson("/api/v1/gyms/{$gym->id}/members/{$member['id']}", $headers)
            ->assertOk()->assertJsonPath('data.first_name', 'Updated')
            ->assertJsonPath('data.email', 'keeper@example.test')
            ->assertJsonPath('data.phone', '+44 7700 900808');
    }

    public function test_active_member_profile_without_a_membership_cannot_create_qr_access(): void
    {
        [$owner, $gym, $branch] = $this->tenant('NO-PLAN');
        $member = app(TenantContext::class)->run($gym, fn () => Member::query()->create([
            'home_branch_id' => $branch->id,
            'member_number' => 'MBR-NO-PLAN',
            'first_name' => 'Active',
            'last_name' => 'Without Plan',
            'status' => MemberStatus::Active,
        ]));

        Sanctum::actingAs($owner);
        $headers = ['X-Gym-ID' => $gym->id];
        $this->getJson("/api/v1/gyms/{$gym->id}/members/{$member->id}", $headers)
            ->assertOk()->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.current_membership', null);
        $this->postJson("/api/v1/gyms/{$gym->id}/members/{$member->id}/access-credential", [], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('membership');
    }

    /** @return array{User, Gym, GymBranch} */
    private function tenant(string $suffix): array
    {
        $owner = User::factory()->create();
        $gym = Gym::factory()->create(['name' => "Lifecycle {$suffix}", 'base_currency' => Currency::GBP]);
        app(TenantContext::class)->run($gym, fn () => $gym->users()->attach($owner, [
            'role' => UserRole::GymOwner->value,
            'status' => 'active',
        ]));
        $branch = app(TenantContext::class)->run($gym, fn () => GymBranch::query()->create([
            'name' => "Branch {$suffix}",
            'code' => "BRANCH-{$suffix}",
            'status' => 'active',
            'is_primary' => true,
        ]));

        return [$owner, $gym, $branch];
    }
}
