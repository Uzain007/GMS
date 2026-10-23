<?php

namespace Tests\Feature;

use App\Enums\AttendanceMethod;
use App\Enums\AttendanceStatus;
use App\Enums\BillingInterval;
use App\Enums\Currency;
use App\Enums\MemberStatus;
use App\Enums\MembershipStatus;
use App\Enums\PlanStatus;
use App\Enums\SaasInvoiceStatus;
use App\Enums\StaffStatus;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Gym;
use App\Models\GymBranch;
use App\Models\GymSubscription;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\PlatformBillingCustomer;
use App\Models\SaasPlan;
use App\Models\SaasPlanChangeRequest;
use App\Models\SaasPlanPrice;
use App\Models\SaasBillingNotification;
use App\Models\SaasBillingInvoice;
use App\Models\StaffAttendanceRecord;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\AutomatedAttendanceCheckoutService;
use App\Support\AuditPresentation;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ImmediateOperationalStabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_important_audit_events_have_business_readable_transition_labels(): void
    {
        $this->assertSame('Gym suspended', AuditPresentation::for(
            'gym.updated', 'Platform Admin', 'Forge Fitness', ['status' => 'active'], ['status' => 'suspended']
        )['title']);
        $this->assertSame('SaaS invoice marked due', AuditPresentation::for(
            'saas.invoice.status_changed', null, 'Forge Fitness', ['status' => 'upcoming'], ['status' => 'due']
        )['title']);
        $this->assertSame('Employee updated', AuditPresentation::for(
            'staff.employee_updated', 'Gym Owner', 'Forge Fitness'
        )['title']);
        $this->assertSame('Member automatically checked out', AuditPresentation::for(
            'attendance.member_auto_checked_out', null, 'Forge Fitness'
        )['title']);
    }

    public function test_member_and_staff_auto_checkout_thresholds_are_idempotent(): void
    {
        [$owner, $gym, $branch] = $this->tenant('AUTO');
        app(TenantContext::class)->run($gym, function () use ($owner, $branch): void {
            $plan = MembershipPlan::query()->create([
                'branch_id' => $branch->id, 'name' => 'Auto plan', 'code' => 'AUTO',
                'billing_interval' => BillingInterval::Monthly, 'interval_count' => 1,
                'price_amount_minor' => 5000, 'currency' => Currency::GBP,
                'joining_fee_minor' => 0, 'trial_days' => 0, 'status' => PlanStatus::Active,
            ]);
            $member = Member::query()->create([
                'home_branch_id' => $branch->id, 'member_number' => 'AUTO-1',
                'first_name' => 'Member', 'last_name' => 'Auto', 'email' => 'member.auto@example.test',
                'phone' => '+44 7700 000001', 'status' => MemberStatus::Active,
            ]);
            $membership = Membership::query()->create([
                'member_id' => $member->id, 'plan_id' => $plan->id, 'branch_id' => $branch->id,
                'created_by' => $owner->id, 'status' => MembershipStatus::Active,
                'starts_at' => today()->subDay(), 'ends_at' => today()->addMonth(),
                'price_amount_minor' => 5000, 'currency' => Currency::GBP,
                'billing_interval' => BillingInterval::Monthly, 'interval_count' => 1,
                'auto_renew' => true, 'grace_period_days' => 7,
            ]);
            AttendanceRecord::query()->create([
                'member_id' => $member->id, 'membership_id' => $membership->id, 'branch_id' => $branch->id,
                'checked_in_by' => $owner->id, 'method' => AttendanceMethod::Manual,
                'status' => AttendanceStatus::CheckedIn, 'checked_in_at' => now()->subHours(7),
            ]);
            $staffUser = User::factory()->create();
            $staff = StaffProfile::query()->create([
                'user_id' => $staffUser->id, 'home_branch_id' => $branch->id,
                'display_name' => 'Staff Auto', 'contact_email' => 'staff.auto@example.test',
                'phone' => '+44 7700 000002', 'employee_number' => 'EMP-AUTO',
                'job_title' => 'Receptionist', 'status' => StaffStatus::Active,
            ]);
            StaffAttendanceRecord::query()->create([
                'staff_profile_id' => $staff->id, 'branch_id' => $branch->id,
                'checked_in_by' => $owner->id, 'method' => AttendanceMethod::Manual,
                'status' => AttendanceStatus::CheckedIn, 'checked_in_at' => now()->subHours(13),
            ]);
            $recentMember = Member::query()->create([
                'home_branch_id' => $branch->id, 'member_number' => 'AUTO-2',
                'first_name' => 'Recent', 'last_name' => 'Member', 'email' => 'recent.member@example.test',
                'phone' => '+44 7700 000003', 'status' => MemberStatus::Active,
            ]);
            $recentMembership = Membership::query()->create([
                'member_id' => $recentMember->id, 'plan_id' => $plan->id, 'branch_id' => $branch->id,
                'created_by' => $owner->id, 'status' => MembershipStatus::Active,
                'starts_at' => today()->subDay(), 'ends_at' => today()->addMonth(),
                'price_amount_minor' => 5000, 'currency' => Currency::GBP,
                'billing_interval' => BillingInterval::Monthly, 'interval_count' => 1,
                'auto_renew' => true, 'grace_period_days' => 7,
            ]);
            AttendanceRecord::query()->create([
                'member_id' => $recentMember->id, 'membership_id' => $recentMembership->id, 'branch_id' => $branch->id,
                'checked_in_by' => $owner->id, 'method' => AttendanceMethod::Manual,
                'status' => AttendanceStatus::CheckedIn, 'checked_in_at' => now()->subHours(5),
            ]);
            AttendanceRecord::query()->create([
                'member_id' => $member->id, 'membership_id' => $membership->id, 'branch_id' => $branch->id,
                'checked_in_by' => $owner->id, 'checked_out_by' => $owner->id,
                'method' => AttendanceMethod::Manual, 'status' => AttendanceStatus::CheckedOut,
                'checked_in_at' => now()->subDay(), 'checked_out_at' => now()->subHours(18),
                'checkout_type' => 'manual',
            ]);
            $recentStaffUser = User::factory()->create();
            $recentStaff = StaffProfile::query()->create([
                'user_id' => $recentStaffUser->id, 'home_branch_id' => $branch->id,
                'display_name' => 'Recent Staff', 'contact_email' => 'recent.staff@example.test',
                'phone' => '+44 7700 000004', 'employee_number' => 'EMP-RECENT',
                'job_title' => 'Receptionist', 'status' => StaffStatus::Active,
            ]);
            StaffAttendanceRecord::query()->create([
                'staff_profile_id' => $recentStaff->id, 'branch_id' => $branch->id,
                'checked_in_by' => $owner->id, 'method' => AttendanceMethod::Manual,
                'status' => AttendanceStatus::CheckedIn, 'checked_in_at' => now()->subHours(11),
            ]);
            StaffAttendanceRecord::query()->create([
                'staff_profile_id' => $staff->id, 'branch_id' => $branch->id,
                'checked_in_by' => $owner->id, 'checked_out_by' => $owner->id,
                'method' => AttendanceMethod::Manual, 'status' => AttendanceStatus::CheckedOut,
                'checked_in_at' => now()->subDay(), 'checked_out_at' => now()->subHours(10),
                'checkout_type' => 'manual',
            ]);
        });

        $first = app(AutomatedAttendanceCheckoutService::class)->runAll();
        $second = app(AutomatedAttendanceCheckoutService::class)->runAll();
        $this->assertSame(1, $first['members']);
        $this->assertSame(1, $first['staff']);
        $this->assertSame(0, $second['members']);
        $this->assertSame(0, $second['staff']);
        app(TenantContext::class)->run($gym, function (): void {
            $this->assertSame(1, AttendanceRecord::query()->where('checkout_type', 'automatic')->count());
            $this->assertSame(1, AttendanceRecord::query()->where('status', 'checked_in')->count());
            $this->assertSame(1, AttendanceRecord::query()->where('checkout_type', 'manual')->count());
            $this->assertSame(1, StaffAttendanceRecord::query()->where('checkout_type', 'automatic')->count());
            $this->assertSame(1, StaffAttendanceRecord::query()->where('status', 'checked_in')->count());
            $this->assertSame(1, StaffAttendanceRecord::query()->where('checkout_type', 'manual')->count());
        });
    }

    public function test_plan_change_requires_super_admin_review_and_prevents_duplicate_pending_requests(): void
    {
        Queue::fake();
        [$owner, $gym] = $this->tenant('PLAN');
        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        [$currentPlan, $currentPrice] = $this->saasPlan('basic', 5000);
        [$requestedPlan, $requestedPrice] = $this->saasPlan('premium', 9000);
        app(TenantContext::class)->run($gym, function () use ($gym, $currentPlan, $currentPrice): void {
            $customer = PlatformBillingCustomer::query()->create([
                'provider_customer_id' => 'cus_plan', 'billing_email' => 'owner@example.test',
                'country_code' => 'GB', 'default_currency' => Currency::GBP,
            ]);
            GymSubscription::query()->create([
                'billing_customer_id' => $customer->id, 'saas_plan_id' => $currentPlan->id,
                'saas_plan_price_id' => $currentPrice->id, 'provider_subscription_id' => 'sub_plan', 'status' => 'active',
                'plan_code_snapshot' => $currentPlan->code, 'plan_name_snapshot' => $currentPlan->name,
                'feature_limits_snapshot' => $currentPlan->feature_limits, 'currency' => Currency::GBP,
                'amount_minor' => $currentPrice->amount_minor, 'billing_interval' => 'monthly',
            ]);
        });

        Sanctum::actingAs($owner);
        $headers = ['X-Gym-ID' => $gym->id];
        $changeId = $this->postJson("/api/v1/gyms/{$gym->id}/saas-plan-change-requests", [
            'saas_plan_price_id' => $requestedPrice->id, 'reason' => 'We need the additional capacity.',
        ], $headers)->assertOk()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->postJson("/api/v1/gyms/{$gym->id}/saas-plan-change-requests", [
            'saas_plan_price_id' => $requestedPrice->id, 'reason' => 'Duplicate request attempt.',
        ], $headers)->assertUnprocessable();
        app(TenantContext::class)->run($gym, fn () => $this->assertSame($currentPlan->id, GymSubscription::query()->current()->sole()->saas_plan_id));

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/platform/saas-plan-change-requests/{$gym->id}/{$changeId}/review", [
            'decision' => 'approve', 'reason' => 'Upgrade approved after account review.',
        ])->assertOk()->assertJsonPath('data.status', 'approved');
        app(TenantContext::class)->run($gym, function () use ($requestedPlan): void {
            $this->assertSame($requestedPlan->id, GymSubscription::query()->current()->sole()->saas_plan_id);
            $this->assertSame('approved', SaasPlanChangeRequest::query()->sole()->status->value);
            $this->assertSame(2, SaasBillingNotification::query()->count());
        });

        Sanctum::actingAs($owner);
        $cancelledId = $this->postJson("/api/v1/gyms/{$gym->id}/saas-plan-change-requests", [
            'saas_plan_price_id' => $currentPrice->id, 'reason' => 'We want to reconsider the approved upgrade.',
        ], $headers)->assertOk()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->postJson("/api/v1/gyms/{$gym->id}/saas-plan-change-requests/{$cancelledId}/cancel", [], $headers)
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $rejectedId = $this->postJson("/api/v1/gyms/{$gym->id}/saas-plan-change-requests", [
            'saas_plan_price_id' => $currentPrice->id, 'reason' => 'Request a reviewed downgrade instead.',
        ], $headers)->assertOk()->assertJsonPath('data.status', 'pending')->json('data.id');
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/platform/saas-plan-change-requests/{$gym->id}/{$rejectedId}/review", [
            'decision' => 'reject', 'reason' => 'The requested downgrade was not approved.',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');
        app(TenantContext::class)->run($gym, function () use ($requestedPlan): void {
            $this->assertSame($requestedPlan->id, GymSubscription::query()->current()->sole()->saas_plan_id);
            $this->assertSame(1, SaasPlanChangeRequest::query()->where('status', 'cancelled')->count());
            $this->assertSame(1, SaasPlanChangeRequest::query()->where('status', 'rejected')->count());
            $this->assertSame(5, SaasBillingNotification::query()->count());
        });

        $this->deleteJson("/api/v1/platform/saas-plans/{$requestedPlan->id}", [
            'reason' => 'Verify referenced plans remain protected.',
        ])->assertUnprocessable()->assertJsonPath('errors.plan.0', 'This plan has subscription, invoice, payment, checkout, or other protected history and cannot be deleted. Archive it instead.');
    }

    public function test_member_and_employee_removal_revoke_tenant_access_but_preserve_platform_history(): void
    {
        [$owner, $gym, $branch] = $this->tenant('LIFECYCLE');
        [$member, $staff] = app(TenantContext::class)->run($gym, function () use ($gym, $branch): array {
            $memberUser = User::factory()->create();
            $gym->users()->attach($memberUser, ['role' => UserRole::Member->value, 'status' => 'active']);
            $member = Member::query()->create([
                'user_id' => $memberUser->id, 'home_branch_id' => $branch->id,
                'member_number' => 'LIFE-MEMBER', 'first_name' => 'Historical', 'last_name' => 'Member',
                'email' => 'historical.member@example.test', 'phone' => '+44 7700 000005',
                'status' => MemberStatus::Active,
            ]);
            $staffUser = User::factory()->create();
            $gym->users()->attach($staffUser, ['role' => UserRole::GymManager->value, 'status' => 'active']);
            $staff = StaffProfile::query()->create([
                'user_id' => $staffUser->id, 'home_branch_id' => $branch->id,
                'display_name' => 'Historical Employee', 'contact_email' => 'historical.staff@example.test',
                'phone' => '+44 7700 000006', 'employee_number' => 'EMP-LIFE',
                'job_title' => 'Manager', 'status' => StaffStatus::Active,
            ]);
            return [$member, $staff];
        });

        Sanctum::actingAs($owner);
        $headers = ['X-Gym-ID' => $gym->id];
        $this->patchJson("/api/v1/gyms/{$gym->id}/members/{$member->id}", [
            'status' => 'suspended', 'reason' => 'Temporary account safety review.',
        ], $headers)->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->deleteJson("/api/v1/gyms/{$gym->id}/members/{$member->id}", [
            'reason' => 'Member left the gym permanently.',
        ], $headers)->assertOk()->assertJsonPath('data.status', 'deleted');
        $this->patchJson("/api/v1/gyms/{$gym->id}/staff/{$staff->id}", [
            'status' => 'suspended', 'reason' => 'Employee access is under review.',
        ], $headers)->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->deleteJson("/api/v1/gyms/{$gym->id}/staff/{$staff->id}", [
            'reason' => 'Employment ended and access must be removed.',
        ], $headers)->assertOk()->assertJsonPath('data.status', 'deleted');

        $this->getJson("/api/v1/gyms/{$gym->id}/members", $headers)
            ->assertOk()->assertJsonMissing(['id' => $member->id]);
        $this->getJson("/api/v1/gyms/{$gym->id}/staff", $headers)
            ->assertOk()->assertJsonMissing(['id' => $staff->id]);
        $this->assertDatabaseHas('gym_user', ['gym_id' => $gym->id, 'user_id' => $member->user_id, 'status' => 'deleted']);
        $this->assertDatabaseHas('gym_user', ['gym_id' => $gym->id, 'user_id' => $staff->user_id, 'status' => 'deleted']);

        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/platform/member-directory?gym_id={$gym->id}&status=deleted")
            ->assertOk()->assertJsonPath('data.0.id', $member->id)
            ->assertJsonPath('data.0.lifecycle_reason', 'Member left the gym permanently.');
        $this->getJson("/api/v1/platform/staff-directory?gym_id={$gym->id}&status=deleted")
            ->assertOk()->assertJsonPath('data.0.id', $staff->id)
            ->assertJsonPath('data.0.lifecycle_reason', 'Employment ended and access must be removed.');
    }

    public function test_super_admin_due_reminders_are_counted_audited_and_deduplicated(): void
    {
        Queue::fake();
        [$owner, $gym] = $this->tenant('REMINDER');
        [$plan, $price] = $this->saasPlan('reminder', 7500);
        $invoice = app(TenantContext::class)->run($gym, function () use ($owner, $plan, $price): SaasBillingInvoice {
            $customer = PlatformBillingCustomer::query()->create([
                'provider_customer_id' => 'cus_reminder', 'billing_email' => $owner->email,
                'country_code' => 'GB', 'default_currency' => Currency::GBP,
            ]);
            $subscription = GymSubscription::query()->create([
                'billing_customer_id' => $customer->id, 'saas_plan_id' => $plan->id,
                'saas_plan_price_id' => $price->id, 'provider_subscription_id' => 'sub_reminder',
                'status' => 'past_due', 'plan_code_snapshot' => $plan->code,
                'plan_name_snapshot' => $plan->name, 'feature_limits_snapshot' => $plan->feature_limits,
                'currency' => Currency::GBP, 'amount_minor' => $price->amount_minor,
                'billing_interval' => 'monthly',
            ]);
            return SaasBillingInvoice::query()->create([
                'billing_customer_id' => $customer->id, 'gym_subscription_id' => $subscription->id,
                'provider_invoice_id' => 'invoice_reminder', 'number' => 'IC-SAAS-2026-00124',
                'status' => SaasInvoiceStatus::PastDue, 'currency' => Currency::GBP,
                'amount_due_minor' => 7500, 'amount_paid_minor' => 0, 'amount_remaining_minor' => 7500,
                'period_start' => now()->subMonth(), 'period_end' => now(), 'due_at' => now()->subDay(),
            ]);
        });

        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/platform/saas-payment-reminders/preview')
            ->assertOk()->assertJsonPath('data.eligible', 1);
        $this->postJson('/api/v1/platform/saas-payment-reminders', [
            'confirmed' => true, 'reason' => 'Platform finance requested a due reminder.',
        ])->assertOk()->assertJsonPath('data.eligible', 1)
            ->assertJsonPath('data.queued', 1)->assertJsonPath('data.duplicate_suppressed', 0);
        $this->postJson("/api/v1/platform/saas-payment-reminders/{$gym->id}/{$invoice->id}", [
            'reason' => 'Repeat reminder must be safely suppressed.',
        ])->assertOk()->assertJsonPath('data.queued', 0)
            ->assertJsonPath('data.duplicate_suppressed', 1);

        app(TenantContext::class)->run($gym, function () use ($invoice): void {
            $this->assertSame(1, SaasBillingNotification::query()
                ->where('saas_billing_invoice_id', $invoice->id)
                ->where('template_key', 'saas_invoice_overdue')->count());
            $this->assertSame(2, $invoice->auditLogs()->where('event', 'saas.payment_reminder.sent')->count());
        });
    }

    /** @return array{User,Gym,GymBranch} */
    private function tenant(string $suffix): array
    {
        $owner = User::factory()->create();
        $gym = Gym::factory()->create(['name' => "Immediate {$suffix}", 'base_currency' => Currency::GBP]);
        app(TenantContext::class)->run($gym, fn () => $gym->users()->attach($owner, ['role' => UserRole::GymOwner->value, 'status' => 'active']));
        $branch = app(TenantContext::class)->run($gym, fn () => GymBranch::query()->create([
            'name' => "Branch {$suffix}", 'code' => "BR-{$suffix}", 'status' => 'active', 'is_primary' => true,
        ]));
        return [$owner, $gym, $branch];
    }

    /** @return array{SaasPlan,SaasPlanPrice} */
    private function saasPlan(string $code, int $amount): array
    {
        $plan = SaasPlan::query()->create([
            'code' => $code, 'name' => ucfirst($code), 'status' => 'active',
            'feature_limits' => ['members' => 500, 'branches' => 2, 'staff' => 20],
            'payment_methods' => ['cash'],
        ]);
        $price = SaasPlanPrice::query()->create([
            'saas_plan_id' => $plan->id, 'currency' => Currency::GBP,
            'billing_interval' => 'monthly', 'amount_minor' => $amount, 'trial_days' => 0, 'active' => true,
        ]);
        return [$plan, $price];
    }
}
