<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\BillingInterval;
use App\Enums\MemberStatus;
use App\Enums\MembershipStatus;
use App\Enums\PlanStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Gym;
use App\Models\GymBranch;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\NotificationDelivery;
use App\Models\Payment;
use App\Models\User;
use App\Services\AutomatedMembershipBillingService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

        $cashPayload = [
            'member_id' => $member['id'],
            'membership_id' => $membership['id'],
            'invoice_id' => $invoice['id'],
            'branch_id' => $branch->id,
            'method' => 'cash',
            'amount_minor' => 5999,
            'currency' => Currency::GBP->value,
            'idempotency_key' => 'membership-lifecycle-cash',
            'payment_date' => today()->toDateString(),
        ];
        $this->postJson("/api/v1/gyms/{$gym->id}/payments", array_diff_key($cashPayload, ['payment_date' => true]), $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('payment_date');

        $payment = $this->postJson("/api/v1/gyms/{$gym->id}/payments", $cashPayload, $headers)->assertCreated()
            ->assertJsonPath('data.membership_id', $membership['id'])
            ->assertJsonPath('data.status', 'paid')
            ->json('data');

        app(TenantContext::class)->run($gym, function () use ($payment, $membership): void {
            $saved = Payment::query()->findOrFail($payment['id']);
            $this->assertSame($membership['id'], $saved->membership_id);
            $this->assertSame(0, $saved->invoice->due_amount_minor);
            $this->assertSame(today()->toDateString(), $saved->paid_at?->toDateString());
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

    public function test_owner_can_amend_membership_contract_with_audit_but_reception_and_other_tenants_cannot(): void
    {
        [$owner, $gym, $branch] = $this->tenant('AMEND');
        [, $otherGym] = $this->tenant('OTHER');
        [$member, $originalPlan, $replacementPlan, $membership] = app(TenantContext::class)->run($gym, function () use ($owner, $branch): array {
            $member = Member::query()->create([
                'home_branch_id' => $branch->id, 'member_number' => 'MBR-AMEND',
                'first_name' => 'Contract', 'last_name' => 'Member',
                'email' => 'contract.member@example.test', 'phone' => '+44 7700 900777',
                'status' => MemberStatus::Active,
            ]);
            $original = $this->createPlan($branch, 'ORIGINAL', 4500);
            $replacement = $this->createPlan($branch, 'REPLACEMENT', 6500);
            $membership = Membership::query()->create([
                'member_id' => $member->id, 'plan_id' => $original->id, 'branch_id' => $branch->id,
                'created_by' => $owner->id, 'status' => MembershipStatus::Active,
                'starts_at' => today()->subMonth(), 'ends_at' => today()->addMonths(2),
                'next_billing_at' => today()->addWeek(), 'price_amount_minor' => $original->price_amount_minor,
                'currency' => $original->currency, 'joining_fee_minor' => 0,
                'billing_interval' => BillingInterval::Monthly, 'interval_count' => 1,
                'auto_renew' => true, 'terms_snapshot' => ['version' => 'original'],
            ]);
            return [$member, $original, $replacement, $membership];
        });
        $otherPlan = app(TenantContext::class)->run($otherGym, fn () => MembershipPlan::query()->create([
            'name' => 'Other gym plan', 'code' => 'OTHER-PLAN', 'billing_interval' => BillingInterval::Monthly,
            'interval_count' => 1, 'price_amount_minor' => 9999, 'currency' => Currency::GBP,
            'joining_fee_minor' => 0, 'trial_days' => 0, 'status' => PlanStatus::Active,
        ]));

        Sanctum::actingAs($owner);
        $headers = ['X-Gym-ID' => $gym->id];
        $this->patchJson("/api/v1/gyms/{$gym->id}/memberships/{$membership->id}", [
            'plan_id' => $replacementPlan->id,
            'ends_at' => today()->addYear()->toDateString(),
            'next_billing_at' => today()->addMonth()->toDateString(),
            'auto_renew' => false,
            'reason' => 'Owner approved a documented contract amendment.',
        ], $headers)->assertOk()
            ->assertJsonPath('data.plan_id', $replacementPlan->id)
            ->assertJsonPath('data.price_amount_minor', 6500)
            ->assertJsonPath('data.auto_renew', false);

        app(TenantContext::class)->run($gym, function () use ($membership, $originalPlan, $replacementPlan): void {
            $saved = Membership::query()->findOrFail($membership->id);
            $this->assertSame($replacementPlan->id, $saved->plan_id);
            $this->assertSame(6500, $saved->price_amount_minor);
            $audit = AuditLog::query()->where('event', 'membership.updated')->latest('created_at')->firstOrFail();
            $this->assertSame($originalPlan->id, $audit->before_values['plan_id']);
            $this->assertSame($replacementPlan->id, $audit->after_values['plan_id']);
            $this->assertSame('Owner approved a documented contract amendment.', $audit->reason);
        });

        $this->patchJson("/api/v1/gyms/{$gym->id}/memberships/{$membership->id}", [
            'plan_id' => $otherPlan->id, 'reason' => 'Attempted cross-gym amendment.',
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('plan_id');

        $reception = User::factory()->create();
        app(TenantContext::class)->run($gym, fn () => $gym->users()->attach($reception->id, [
            'role' => UserRole::Receptionist->value, 'status' => 'active', 'joined_at' => now(),
        ]));
        Sanctum::actingAs($reception);
        $this->patchJson("/api/v1/gyms/{$gym->id}/memberships/{$membership->id}", [
            'auto_renew' => true, 'reason' => 'Reception must not amend contracts.',
        ], $headers)->assertForbidden();
    }

    public function test_overdue_membership_invoice_restricts_access_once_and_cash_payment_restores_it(): void
    {
        Queue::fake();
        [$owner, $gym, $branch] = $this->tenant('OVERDUE');
        [$member, $membership] = app(TenantContext::class)->run($gym, function () use ($owner, $branch): array {
            $member = Member::query()->create([
                'home_branch_id' => $branch->id, 'member_number' => '541116',
                'first_name' => 'Overdue', 'last_name' => 'Member',
                'email' => 'overdue.member@example.test', 'phone' => '+44 7700 900778',
                'status' => MemberStatus::Active,
            ]);
            $plan = $this->createPlan($branch, 'OVERDUE', 5500);
            $membership = Membership::query()->create([
                'member_id' => $member->id, 'plan_id' => $plan->id, 'branch_id' => $branch->id,
                'created_by' => $owner->id, 'status' => MembershipStatus::Active,
                'starts_at' => today()->subMonths(2), 'ends_at' => today()->addYear(),
                'next_billing_at' => today()->subDays(9), 'price_amount_minor' => 5500,
                'currency' => Currency::GBP, 'joining_fee_minor' => 0,
                'billing_interval' => BillingInterval::Monthly, 'interval_count' => 1,
                'auto_renew' => true, 'grace_period_days' => 7,
            ]);
            return [$member, $membership];
        });

        Sanctum::actingAs($owner);
        $headers = ['X-Gym-ID' => $gym->id];
        $credential = $this->postJson("/api/v1/gyms/{$gym->id}/members/{$member->id}/access-credential", [], $headers)
            ->assertCreated()->json('data.credential');

        $first = app(TenantContext::class)->run($gym, fn () => app(AutomatedMembershipBillingService::class)->processTenant($gym));
        $second = app(TenantContext::class)->run($gym, fn () => app(AutomatedMembershipBillingService::class)->processTenant($gym));
        $this->assertSame(['invoices_created' => 1, 'reminders_queued' => 1, 'restricted' => 1], $first);
        $this->assertSame(['invoices_created' => 0, 'reminders_queued' => 0, 'restricted' => 0], $second);

        $invoice = app(TenantContext::class)->run($gym, function () use ($membership): Invoice {
            $this->assertSame(1, Invoice::query()->where('membership_id', $membership->id)->count());
            $this->assertSame(1, NotificationDelivery::query()->where('template_key', 'membership_payment_due')->count());
            return Invoice::query()->where('membership_id', $membership->id)->firstOrFail();
        });
        $this->postJson("/api/v1/gyms/{$gym->id}/attendance/check-ins", [
            'branch_id' => $branch->id, 'member_code' => $member->member_code,
        ], $headers)->assertUnprocessable()
            ->assertJsonPath('errors.membership.0', 'Your membership payment is overdue. Please contact your gym.');
        $this->postJson("/api/v1/gyms/{$gym->id}/attendance/check-ins", [
            'branch_id' => $branch->id, 'credential' => $credential,
        ], $headers)->assertUnprocessable()
            ->assertJsonPath('errors.membership.0', 'Your membership payment is overdue. Please contact your gym.');

        $this->postJson("/api/v1/gyms/{$gym->id}/payments", [
            'member_id' => $member->id, 'membership_id' => $membership->id,
            'invoice_id' => $invoice->id, 'branch_id' => $branch->id,
            'method' => 'cash', 'amount_minor' => 5500, 'currency' => Currency::GBP->value,
            'idempotency_key' => 'overdue-membership-cash', 'payment_date' => today()->toDateString(),
        ], $headers)->assertCreated()->assertJsonPath('data.status', 'paid');

        app(TenantContext::class)->run($gym, function () use ($membership): void {
            $restored = Membership::query()->findOrFail($membership->id);
            $this->assertNull($restored->billing_restricted_at);
            $this->assertTrue($restored->next_billing_at->isFuture());
            $this->assertDatabaseHas('audit_logs', ['gym_id' => $restored->gym_id, 'event' => 'membership.invoice.settled']);
        });
        $this->postJson("/api/v1/gyms/{$gym->id}/attendance/check-ins", [
            'branch_id' => $branch->id, 'member_code' => $member->member_code,
        ], $headers)->assertCreated();
    }

    private function createPlan(GymBranch $branch, string $suffix, int $price): MembershipPlan
    {
        return MembershipPlan::query()->create([
            'branch_id' => $branch->id, 'name' => "{$suffix} plan", 'code' => "PLAN-{$suffix}",
            'billing_interval' => BillingInterval::Monthly, 'interval_count' => 1,
            'price_amount_minor' => $price, 'currency' => Currency::GBP,
            'joining_fee_minor' => 0, 'trial_days' => 0, 'status' => PlanStatus::Active,
            'terms' => ['version' => mb_strtolower($suffix)],
        ]);
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
