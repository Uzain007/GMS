<?php

namespace Tests\Feature;

use App\Enums\BillingInterval;
use App\Enums\ClassBookingStatus;
use App\Enums\Currency;
use App\Enums\MemberStatus;
use App\Enums\MembershipStatus;
use App\Enums\PlanStatus;
use App\Enums\UserRole;
use App\Models\ClassBooking;
use App\Models\Gym;
use App\Models\GymBranch;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhaseFiveAttendanceBookingIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cross_tenant_member_code_cannot_be_checked_in(): void
    {
        [$owner, $gym, $branch] = $this->tenant();
        [, $otherGym, $otherBranch] = $this->tenant();
        $other = app(TenantContext::class)->run($otherGym, fn () => $this->memberWithMembership($otherBranch, 'MBR-OTHER'));

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/gyms/{$gym->id}/attendance/check-ins", [
            'branch_id' => $branch->id,
            'member_code' => $other->member_code,
        ], ['X-Gym-ID' => $gym->id])->assertUnprocessable();
    }

    public function test_every_member_receives_a_six_digit_code_unique_inside_the_gym(): void
    {
        [, $gym, $branch] = $this->tenant();
        [$first, $second] = app(TenantContext::class)->run($gym, fn () => [
            $this->memberWithMembership($branch, 'MBR-CODE-A'),
            $this->memberWithMembership($branch, 'MBR-CODE-B'),
        ]);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $first->member_code);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $second->member_code);
        $this->assertNotSame($first->member_code, $second->member_code);
        $this->assertNotSame($first->id, $first->member_code);
    }

    public function test_manual_member_code_check_in_is_validated_by_the_backend(): void
    {
        [$owner, $gym, $branch] = $this->tenant();
        $member = app(TenantContext::class)->run($gym, fn () => $this->memberWithMembership($branch, 'MBR-MANUAL'));

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/gyms/{$gym->id}/attendance/check-ins", [
            'branch_id' => $branch->id,
            'member_code' => $member->member_code,
        ], ['X-Gym-ID' => $gym->id])
            ->assertCreated()
            ->assertJsonPath('data.member.member_code', $member->member_code)
            ->assertJsonPath('data.method', 'member_code');
    }

    public function test_valid_secure_qr_checks_in_once_and_rejects_a_duplicate(): void
    {
        [$owner, $gym, $branch] = $this->tenant();
        $member = app(TenantContext::class)->run($gym, fn () => $this->memberWithMembership($branch, 'MBR-QR'));

        Sanctum::actingAs($owner);
        $credential = $this->postJson("/api/v1/gyms/{$gym->id}/members/{$member->id}/access-credential", [], [
            'X-Gym-ID' => $gym->id,
        ])->assertCreated()->json('data.credential');

        $payload = ['branch_id' => $branch->id, 'credential' => $credential];
        $this->postJson("/api/v1/gyms/{$gym->id}/attendance/check-ins", $payload, ['X-Gym-ID' => $gym->id])
            ->assertCreated()->assertJsonPath('data.method', 'qr');
        $this->postJson("/api/v1/gyms/{$gym->id}/attendance/check-ins", $payload, ['X-Gym-ID' => $gym->id])
            ->assertUnprocessable()->assertJsonValidationErrors('member');
    }

    public function test_secure_qr_rejects_expired_membership_wrong_gym_and_wrong_branch(): void
    {
        [$owner, $gym, $branch] = $this->tenant();
        $member = app(TenantContext::class)->run($gym, fn () => $this->memberWithMembership($branch, 'MBR-RULES'));
        $otherBranch = app(TenantContext::class)->run($gym, fn () => GymBranch::query()->create([
            'name' => 'North', 'code' => 'NORTH', 'status' => 'active', 'is_primary' => false,
        ]));

        Sanctum::actingAs($owner);
        $credential = $this->postJson("/api/v1/gyms/{$gym->id}/members/{$member->id}/access-credential", [], [
            'X-Gym-ID' => $gym->id,
        ])->assertCreated()->json('data.credential');

        $this->postJson("/api/v1/gyms/{$gym->id}/attendance/check-ins", [
            'branch_id' => $otherBranch->id, 'credential' => $credential,
        ], ['X-Gym-ID' => $gym->id])->assertUnprocessable()->assertJsonValidationErrors('branch_id');

        app(TenantContext::class)->run($gym, fn () => Membership::query()
            ->where('member_id', $member->id)->update(['ends_at' => today()->subDay()]));
        $this->postJson("/api/v1/gyms/{$gym->id}/attendance/check-ins", [
            'branch_id' => $branch->id, 'credential' => $credential,
        ], ['X-Gym-ID' => $gym->id])->assertUnprocessable()->assertJsonValidationErrors('membership');

        [$otherOwner, $otherGym, $otherGymBranch] = $this->tenant();
        $otherMember = app(TenantContext::class)->run($otherGym, fn () => $this->memberWithMembership($otherGymBranch, 'MBR-OTHER-QR'));
        Sanctum::actingAs($otherOwner);
        $otherCredential = $this->postJson("/api/v1/gyms/{$otherGym->id}/members/{$otherMember->id}/access-credential", [], [
            'X-Gym-ID' => $otherGym->id,
        ])->assertCreated()->json('data.credential');

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/gyms/{$gym->id}/attendance/check-ins", [
            'branch_id' => $branch->id, 'credential' => $otherCredential,
        ], ['X-Gym-ID' => $gym->id])->assertUnprocessable()->assertJsonValidationErrors('credential');
    }

    public function test_full_class_waitlists_and_cancellation_promotes_fifo_member(): void
    {
        [$owner, $gym, $branch] = $this->tenant();
        [$first, $second] = app(TenantContext::class)->run($gym, function () use ($branch): array {
            return [
                $this->memberWithMembership($branch, 'MBR-FIRST'),
                $this->memberWithMembership($branch, 'MBR-SECOND'),
            ];
        });

        Sanctum::actingAs($owner);
        $session = $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions", [
            'branch_id' => $branch->id,
            'title' => 'Strength class',
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHour()->toIso8601String(),
            'capacity' => 1,
            'waitlist_enabled' => true,
        ], ['X-Gym-ID' => $gym->id])->assertSuccessful()->json('data');

        $confirmed = $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions/{$session['id']}/bookings", [
            'member_id' => $first->id,
        ], ['X-Gym-ID' => $gym->id])->assertJsonPath('data.status', 'booked')->json('data');
        $waitlisted = $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions/{$session['id']}/bookings", [
            'member_id' => $second->id,
        ], ['X-Gym-ID' => $gym->id])->assertJsonPath('data.status', 'waitlisted')->json('data');

        $this->postJson("/api/v1/gyms/{$gym->id}/class-bookings/{$confirmed['id']}/cancel", [
            'reason' => 'Member cannot attend',
        ], ['X-Gym-ID' => $gym->id])->assertSuccessful();

        app(TenantContext::class)->run($gym, function () use ($waitlisted): void {
            $this->assertSame(ClassBookingStatus::Booked, ClassBooking::query()->findOrFail($waitlisted['id'])->status);
        });
    }

    /** @return array{User, Gym, GymBranch} */
    private function tenant(): array
    {
        $owner = User::factory()->create();
        $gym = Gym::factory()->create(['base_currency' => Currency::GBP]);
        app(TenantContext::class)->run($gym, function () use ($gym, $owner): void {
            $gym->users()->attach($owner, ['role' => UserRole::GymOwner->value, 'status' => 'active']);
        });
        $branch = app(TenantContext::class)->run($gym, fn () => GymBranch::query()->create([
            'name' => 'Central', 'code' => 'CENTRAL', 'status' => 'active', 'is_primary' => true,
        ]));
        return [$owner, $gym, $branch];
    }

    private function memberWithMembership(GymBranch $branch, string $number): Member
    {
        $member = Member::query()->create([
            'home_branch_id' => $branch->id, 'member_number' => $number,
            'first_name' => 'Test', 'last_name' => $number, 'status' => MemberStatus::Active,
        ]);
        $plan = MembershipPlan::query()->create([
            'branch_id' => $branch->id, 'name' => 'Active plan', 'code' => 'PLAN-'.$number,
            'billing_interval' => BillingInterval::Monthly, 'price_amount_minor' => 5000,
            'currency' => Currency::GBP, 'status' => PlanStatus::Active,
        ]);
        Membership::query()->create([
            'member_id' => $member->id, 'plan_id' => $plan->id, 'branch_id' => $branch->id,
            'created_by' => User::factory()->create()->id, 'status' => MembershipStatus::Active,
            'starts_at' => today()->subDay(), 'price_amount_minor' => 5000,
            'currency' => Currency::GBP, 'billing_interval' => BillingInterval::Monthly,
        ]);
        return $member;
    }
}
