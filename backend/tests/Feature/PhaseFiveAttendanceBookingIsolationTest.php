<?php

namespace Tests\Feature;

use App\Enums\AttendanceMethod;
use App\Enums\AttendanceStatus;
use App\Enums\BillingInterval;
use App\Enums\ClassBookingStatus;
use App\Enums\Currency;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\PlanStatus;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\ClassBooking;
use App\Models\ClassSession;
use App\Models\Gym;
use App\Models\GymBranch;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
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

    public function test_local_calendar_day_allows_membership_qr_and_check_in_before_utc_day_changes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-24 20:30:00', 'UTC'));

        try {
            [$owner, $gym, $branch] = $this->tenant();
            $gym->update(['timezone' => 'Asia/Karachi']);
            $member = app(TenantContext::class)->run($gym, function () use ($branch): Member {
                $member = $this->memberWithMembership($branch, 'MBR-LOCAL-DAY');
                Membership::query()->where('member_id', $member->getKey())->update([
                    'starts_at' => '2026-09-25',
                    'ends_at' => '2026-09-25',
                ]);

                return $member;
            });

            Sanctum::actingAs($owner);
            $headers = ['X-Gym-ID' => $gym->id];
            $credential = $this->postJson(
                "/api/v1/gyms/{$gym->id}/members/{$member->id}/access-credential",
                [],
                $headers,
            )->assertCreated()->json('data.credential');

            $attendanceId = $this->postJson("/api/v1/gyms/{$gym->id}/attendance/check-ins", [
                'branch_id' => $branch->id,
                'credential' => $credential,
            ], $headers)->assertCreated()->assertJsonPath('data.method', 'qr')->json('data.id');

            $this->getJson("/api/v1/gyms/{$gym->id}/attendance", $headers)
                ->assertOk()
                ->assertJsonFragment(['id' => $attendanceId]);
        } finally {
            $this->travelBack();
        }
    }

    public function test_single_primary_location_is_resolved_when_mobile_frontend_has_no_branch_value(): void
    {
        [$owner, $gym, $branch] = $this->tenant();
        $member = app(TenantContext::class)->run($gym, fn () => $this->memberWithMembership($branch, 'MBR-PRIMARY'));

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/gyms/{$gym->id}/attendance/check-ins", [
            'member_code' => $member->member_code,
        ], ['X-Gym-ID' => $gym->id])
            ->assertCreated()
            ->assertJsonPath('data.branch_id', $branch->id);
    }

    public function test_missing_branch_still_fails_closed_for_multiple_active_locations(): void
    {
        [$owner, $gym, $branch] = $this->tenant();
        $member = app(TenantContext::class)->run($gym, fn () => $this->memberWithMembership($branch, 'MBR-MULTI'));
        app(TenantContext::class)->run($gym, fn () => GymBranch::query()->create([
            'name' => 'North', 'code' => 'NORTH', 'status' => 'active', 'is_primary' => false,
        ]));

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/gyms/{$gym->id}/attendance/check-ins", [
            'member_code' => $member->member_code,
        ], ['X-Gym-ID' => $gym->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');
    }

    public function test_member_code_is_persistent_and_identical_across_profile_qr_and_manual_check_in(): void
    {
        [$owner, $gym, $branch] = $this->tenant();
        $existing = app(TenantContext::class)->run($gym, fn () => $this->memberWithMembership($branch, 'MBR-PERSISTENT'));
        $code = $existing->member_code;

        Sanctum::actingAs($owner);
        $headers = ['X-Gym-ID' => $gym->id];

        $this->getJson("/api/v1/gyms/{$gym->id}/members/{$existing->id}", $headers)
            ->assertOk()
            ->assertJsonPath('data.member_code', $code);

        $issued = $this->postJson("/api/v1/gyms/{$gym->id}/members/{$existing->id}/access-credential", [], $headers)
            ->assertCreated()
            ->assertJsonPath('data.member_code', $code)->json('data');
        $this->getJson("/api/v1/gyms/{$gym->id}/members/{$existing->id}/access-credential", $headers)
            ->assertOk()->assertJsonPath('data.member_code', $code)
            ->assertJsonPath('data.credential', $issued['credential']);

        $this->patchJson("/api/v1/gyms/{$gym->id}/members/{$existing->id}", [
            'first_name' => 'Updated',
            'reason' => 'Confirm the visible code remains stable.',
        ], $headers)->assertOk()->assertJsonPath('data.member_code', $code);

        $this->postJson("/api/v1/gyms/{$gym->id}/attendance/check-ins", [
            'branch_id' => $branch->id,
            'member_code' => $code,
        ], $headers)->assertCreated()->assertJsonPath('data.member.member_code', $code);

        $createdCode = $this->postJson("/api/v1/gyms/{$gym->id}/members", [
            'first_name' => 'New',
            'last_name' => 'Member',
            'email' => 'new.member@example.test',
            'phone' => '+44 7700 900503',
            'status' => 'active',
        ], $headers)->assertCreated()->json('data.member_code');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $createdCode);
        $this->assertNotSame($code, $createdCode);
        $this->assertSame($code, app(TenantContext::class)->run($gym, fn () => $existing->fresh()->member_code));
    }

    public function test_valid_secure_qr_checks_in_once_and_rejects_a_duplicate(): void
    {
        [$owner, $gym, $branch] = $this->tenant();
        $member = app(TenantContext::class)->run($gym, fn () => $this->memberWithMembership($branch, 'MBR-QR'));

        Sanctum::actingAs($owner);
        $credential = $this->postJson("/api/v1/gyms/{$gym->id}/members/{$member->id}/access-credential", [], [
            'X-Gym-ID' => $gym->id,
        ])->assertCreated()->assertJsonPath('data.member_code', $member->member_code)->json('data.credential');

        $payload = ['branch_id' => $branch->id, 'credential' => $credential];
        $this->postJson("/api/v1/gyms/{$gym->id}/attendance/check-ins", $payload, ['X-Gym-ID' => $gym->id])
            ->assertCreated()->assertJsonPath('data.method', 'qr');
        app(TenantContext::class)->run($gym, function (): void {
            $this->assertSame(1, AttendanceRecord::query()->where('method', AttendanceMethod::Qr->value)->count());
        });
        $this->postJson("/api/v1/gyms/{$gym->id}/attendance/check-ins", $payload, ['X-Gym-ID' => $gym->id])
            ->assertUnprocessable()->assertJsonValidationErrors('member');
    }

    public function test_authorized_front_desk_manual_check_in_creates_gym_attendance(): void
    {
        [$owner, $gym, $branch] = $this->tenant();
        [$member, $receptionist] = app(TenantContext::class)->run($gym, function () use ($gym, $branch): array {
            $member = $this->memberWithMembership($branch, 'MBR-FRONT-DESK');
            $receptionist = User::factory()->create();
            $gym->users()->attach($receptionist, ['role' => UserRole::Receptionist->value, 'status' => 'active']);

            return [$member, $receptionist];
        });
        $headers = ['X-Gym-ID' => $gym->id];

        Sanctum::actingAs($owner);
        $session = $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions", [
            'branch_id' => $branch->id,
            'title' => 'Front desk remains gym-only',
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHour()->toIso8601String(),
            'capacity' => 1,
            'waitlist_enabled' => false,
        ], $headers)->assertSuccessful()->json('data');
        $booking = $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions/{$session['id']}/bookings", [
            'member_id' => $member->id,
        ], $headers)->assertJsonPath('data.status', 'booked')->json('data');

        Sanctum::actingAs($receptionist);
        $this->postJson("/api/v1/gyms/{$gym->id}/attendance/check-ins", [
            'branch_id' => $branch->id,
            'member_id' => $member->id,
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('data.method', 'manual');

        app(TenantContext::class)->run($gym, function () use ($receptionist, $booking, $session): void {
            $attendance = AttendanceRecord::query()->sole();
            $this->assertSame(AttendanceMethod::Manual, $attendance->method);
            $this->assertSame($receptionist->id, $attendance->checked_in_by);
            $this->assertSame(ClassBookingStatus::Booked, ClassBooking::query()->findOrFail($booking['id'])->status);
            $this->assertSame(0, ClassSession::query()->findOrFail($session['id'])->attended_count);
        });
    }

    public function test_class_present_and_absent_statuses_do_not_create_gym_attendance(): void
    {
        [$owner, $gym, $branch] = $this->tenant();
        [$present, $absent] = app(TenantContext::class)->run($gym, fn (): array => [
            $this->memberWithMembership($branch, 'MBR-CLASS-PRESENT'),
            $this->memberWithMembership($branch, 'MBR-CLASS-ABSENT'),
        ]);

        Sanctum::actingAs($owner);
        $headers = ['X-Gym-ID' => $gym->id];
        $session = $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions", [
            'branch_id' => $branch->id,
            'title' => 'Roster-only attendance',
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHour()->toIso8601String(),
            'capacity' => 2,
            'waitlist_enabled' => true,
        ], $headers)->assertSuccessful()->json('data');

        $presentBooking = $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions/{$session['id']}/bookings", [
            'member_id' => $present->id,
        ], $headers)->assertSuccessful()->json('data');
        $absentBooking = $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions/{$session['id']}/bookings", [
            'member_id' => $absent->id,
        ], $headers)->assertSuccessful()->json('data');
        $this->moveClassToStarted($gym, $session['id']);

        $this->postJson("/api/v1/gyms/{$gym->id}/class-bookings/{$presentBooking['id']}/attend", [], $headers)
            ->assertOk()->assertJsonPath('data.status', 'attended');
        $this->postJson("/api/v1/gyms/{$gym->id}/class-bookings/{$presentBooking['id']}/attend", [], $headers)
            ->assertOk()->assertJsonPath('data.status', 'attended');
        $this->postJson("/api/v1/gyms/{$gym->id}/class-bookings/{$absentBooking['id']}/no-show", [], $headers)
            ->assertOk()->assertJsonPath('data.status', 'no_show');
        $this->postJson("/api/v1/gyms/{$gym->id}/class-bookings/{$absentBooking['id']}/no-show", [], $headers)
            ->assertOk()->assertJsonPath('data.status', 'no_show');
        $this->postJson("/api/v1/gyms/{$gym->id}/class-bookings/{$presentBooking['id']}/no-show", [], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('booking');
        $this->postJson("/api/v1/gyms/{$gym->id}/class-bookings/{$absentBooking['id']}/attend", [], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('booking');

        app(TenantContext::class)->run($gym, function () use ($session): void {
            $this->assertSame(0, AttendanceRecord::query()->count());
            $this->assertSame(2, ClassBooking::query()->count());
            $this->assertSame(1, ClassSession::query()->findOrFail($session['id'])->attended_count);
        });
        $this->getJson("/api/v1/gyms/{$gym->id}/attendance", $headers)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $classDay = now($gym->timezone)->toDateString();
        $this->getJson(
            "/api/v1/gyms/{$gym->id}/reports/overview?from={$classDay}&to={$classDay}&currency=GBP",
            $headers,
        )->assertOk()
            ->assertJsonPath('data.summary.attendance_visits', 0)
            ->assertJsonPath('data.class_performance.attended', 1);
    }

    public function test_qr_gym_check_in_does_not_mark_class_booking_attended(): void
    {
        [$owner, $gym, $branch] = $this->tenant();
        $member = app(TenantContext::class)->run($gym, fn () => $this->memberWithMembership($branch, 'MBR-QR-CLASS-SEPARATE'));

        Sanctum::actingAs($owner);
        $headers = ['X-Gym-ID' => $gym->id];
        $session = $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions", [
            'branch_id' => $branch->id,
            'title' => 'QR remains gym-only',
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHour()->toIso8601String(),
            'capacity' => 1,
            'waitlist_enabled' => false,
        ], $headers)->assertSuccessful()->json('data');
        $booking = $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions/{$session['id']}/bookings", [
            'member_id' => $member->id,
        ], $headers)->assertJsonPath('data.status', 'booked')->json('data');
        $credential = $this->postJson("/api/v1/gyms/{$gym->id}/members/{$member->id}/access-credential", [], $headers)
            ->assertCreated()->json('data.credential');

        $this->postJson("/api/v1/gyms/{$gym->id}/attendance/check-ins", [
            'branch_id' => $branch->id,
            'credential' => $credential,
        ], $headers)->assertCreated()->assertJsonPath('data.method', 'qr');

        app(TenantContext::class)->run($gym, function () use ($booking, $session): void {
            $this->assertSame(1, AttendanceRecord::query()->where('method', AttendanceMethod::Qr->value)->count());
            $this->assertSame(ClassBookingStatus::Booked, ClassBooking::query()->findOrFail($booking['id'])->status);
            $this->assertSame(0, ClassSession::query()->findOrFail($session['id'])->attended_count);
        });
    }

    public function test_member_code_gym_check_in_does_not_mark_class_booking_attended(): void
    {
        [$owner, $gym, $branch] = $this->tenant();
        $member = app(TenantContext::class)->run($gym, fn () => $this->memberWithMembership($branch, 'MBR-CODE-CLASS-SEPARATE'));

        Sanctum::actingAs($owner);
        $headers = ['X-Gym-ID' => $gym->id];
        $session = $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions", [
            'branch_id' => $branch->id,
            'title' => 'Member code remains gym-only',
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHour()->toIso8601String(),
            'capacity' => 1,
            'waitlist_enabled' => false,
        ], $headers)->assertSuccessful()->json('data');
        $booking = $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions/{$session['id']}/bookings", [
            'member_id' => $member->id,
        ], $headers)->assertJsonPath('data.status', 'booked')->json('data');

        $this->postJson("/api/v1/gyms/{$gym->id}/attendance/check-ins", [
            'branch_id' => $branch->id,
            'member_code' => $member->member_code,
        ], $headers)->assertCreated()->assertJsonPath('data.method', 'member_code');

        app(TenantContext::class)->run($gym, function () use ($booking, $session): void {
            $this->assertSame(1, AttendanceRecord::query()->where('method', AttendanceMethod::MemberCode->value)->count());
            $this->assertSame(ClassBookingStatus::Booked, ClassBooking::query()->findOrFail($booking['id'])->status);
            $this->assertSame(0, ClassSession::query()->findOrFail($session['id'])->attended_count);
        });
    }

    public function test_class_attendance_and_gym_check_in_remain_independent(): void
    {
        [$owner, $gym, $branch] = $this->tenant();
        $member = app(TenantContext::class)->run($gym, fn () => $this->memberWithMembership($branch, 'MBR-CLASS-AND-GYM'));

        Sanctum::actingAs($owner);
        $headers = ['X-Gym-ID' => $gym->id];
        $session = $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions", [
            'branch_id' => $branch->id,
            'title' => 'Independent class and gym attendance',
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHour()->toIso8601String(),
            'capacity' => 1,
            'waitlist_enabled' => false,
        ], $headers)->assertSuccessful()->json('data');
        $booking = $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions/{$session['id']}/bookings", [
            'member_id' => $member->id,
        ], $headers)->assertJsonPath('data.status', 'booked')->json('data');

        $this->postJson("/api/v1/gyms/{$gym->id}/class-bookings/{$booking['id']}/attend", [], $headers)
            ->assertOk()->assertJsonPath('data.status', 'attended');
        app(TenantContext::class)->run($gym, fn () => $this->assertSame(0, AttendanceRecord::query()->count()));

        $this->postJson("/api/v1/gyms/{$gym->id}/attendance/check-ins", [
            'branch_id' => $branch->id,
            'member_code' => $member->member_code,
        ], $headers)->assertCreated()->assertJsonPath('data.method', 'member_code');

        app(TenantContext::class)->run($gym, function () use ($booking, $session): void {
            $this->assertSame(1, AttendanceRecord::query()->count());
            $this->assertSame(ClassBookingStatus::Attended, ClassBooking::query()->findOrFail($booking['id'])->status);
            $this->assertSame(1, ClassSession::query()->findOrFail($session['id'])->attended_count);
        });
        $this->getJson("/api/v1/gyms/{$gym->id}/attendance", $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $from = now($gym->timezone)->toDateString();
        $to = now($gym->timezone)->addDay()->toDateString();
        $this->getJson(
            "/api/v1/gyms/{$gym->id}/reports/overview?from={$from}&to={$to}&currency=GBP",
            $headers,
        )->assertOk()
            ->assertJsonPath('data.summary.attendance_visits', 1)
            ->assertJsonPath('data.class_performance.attended', 1);
    }

    public function test_replacing_a_secure_qr_invalidates_the_old_scanner_value_and_keeps_the_new_one_valid(): void
    {
        [$owner, $gym, $branch] = $this->tenant();
        $member = app(TenantContext::class)->run($gym, fn () => $this->memberWithMembership($branch, 'MBR-ROTATED-QR'));
        $headers = ['X-Gym-ID' => $gym->id];

        Sanctum::actingAs($owner);
        $original = $this->postJson("/api/v1/gyms/{$gym->id}/members/{$member->id}/access-credential", [], $headers)
            ->assertCreated()->json('data.credential');
        $replacement = $this->postJson("/api/v1/gyms/{$gym->id}/members/{$member->id}/access-credential/rotate", [], $headers)
            ->assertCreated()->json('data.credential');

        $this->assertNotSame($original, $replacement);
        $this->postJson("/api/v1/gyms/{$gym->id}/attendance/check-ins", [
            'branch_id' => $branch->id, 'credential' => $original,
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('credential');
        $this->postJson("/api/v1/gyms/{$gym->id}/attendance/check-ins", [
            'branch_id' => $branch->id, 'credential' => $replacement,
        ], $headers)->assertCreated()->assertJsonPath('data.method', 'qr');
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

    public function test_stale_open_presence_is_closed_before_a_new_check_in(): void
    {
        [$owner, $gym, $branch] = $this->tenant();
        $member = app(TenantContext::class)->run($gym, fn () => $this->memberWithMembership($branch, 'MBR-STALE'));
        $stale = app(TenantContext::class)->run($gym, function () use ($member, $branch, $owner): AttendanceRecord {
            $membership = Membership::query()->where('member_id', $member->id)->firstOrFail();

            return AttendanceRecord::query()->create([
                'member_id' => $member->id,
                'membership_id' => $membership->id,
                'branch_id' => $branch->id,
                'checked_in_by' => $owner->id,
                'method' => AttendanceMethod::Manual,
                'status' => AttendanceStatus::CheckedIn,
                'checked_in_at' => now()->subHours(25),
            ]);
        });

        Sanctum::actingAs($owner);
        $createdId = $this->postJson("/api/v1/gyms/{$gym->id}/attendance/check-ins", [
            'branch_id' => $branch->id,
            'member_code' => $member->member_code,
        ], ['X-Gym-ID' => $gym->id])->assertCreated()->json('data.id');

        app(TenantContext::class)->run($gym, function () use ($stale, $createdId): void {
            $this->assertSame(AttendanceStatus::CheckedOut, $stale->fresh()->status);
            $this->assertSame(
                $stale->checked_in_at->addHours(24)->toIso8601String(),
                $stale->fresh()->checked_out_at->toIso8601String(),
            );
            $this->assertSame(1, AttendanceRecord::query()
                ->where('id', $createdId)
                ->where('status', AttendanceStatus::CheckedIn->value)
                ->count());
        });
    }

    public function test_previous_day_open_presence_stays_visible_and_blocks_a_duplicate(): void
    {
        [$owner, $gym, $branch] = $this->tenant();
        $member = app(TenantContext::class)->run($gym, fn () => $this->memberWithMembership($branch, 'MBR-OPEN'));
        $open = app(TenantContext::class)->run($gym, function () use ($member, $branch, $owner): AttendanceRecord {
            $membership = Membership::query()->where('member_id', $member->id)->firstOrFail();

            return AttendanceRecord::query()->create([
                'member_id' => $member->id,
                'membership_id' => $membership->id,
                'branch_id' => $branch->id,
                'checked_in_by' => $owner->id,
                'method' => AttendanceMethod::Manual,
                'status' => AttendanceStatus::CheckedIn,
                'checked_in_at' => now()->subHours(23),
            ]);
        });

        Sanctum::actingAs($owner);
        $headers = ['X-Gym-ID' => $gym->id];
        $this->getJson("/api/v1/gyms/{$gym->id}/attendance", $headers)
            ->assertOk()
            ->assertJsonFragment(['id' => $open->id]);
        $this->postJson("/api/v1/gyms/{$gym->id}/attendance/check-ins", [
            'branch_id' => $branch->id,
            'member_code' => $member->member_code,
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('member');
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

        $this->getJson("/api/v1/gyms/{$gym->id}/class-sessions/{$session['id']}/bookings?per_page=100", [
            'X-Gym-ID' => $gym->id,
        ])->assertOk()
            ->assertJsonFragment(['id' => $waitlisted['id'], 'status' => 'waitlisted'])
            ->assertJsonFragment(['id' => $second->id, 'member_number' => 'MBR-SECOND']);

        $this->postJson("/api/v1/gyms/{$gym->id}/class-bookings/{$confirmed['id']}/cancel", [
            'reason' => 'Member cannot attend',
        ], ['X-Gym-ID' => $gym->id])->assertSuccessful();

        app(TenantContext::class)->run($gym, function () use ($waitlisted): void {
            $this->assertSame(ClassBookingStatus::Booked, ClassBooking::query()->findOrFail($waitlisted['id'])->status);
        });
    }

    public function test_marking_attendance_does_not_release_a_confirmed_place_or_promote_waitlist(): void
    {
        [$owner, $gym, $branch] = $this->tenant();
        [$confirmedMember, $waitingMember] = app(TenantContext::class)->run($gym, function () use ($branch): array {
            return [
                $this->memberWithMembership($branch, 'MBR-ATTENDED'),
                $this->memberWithMembership($branch, 'MBR-WAITING'),
            ];
        });

        Sanctum::actingAs($owner);
        $headers = ['X-Gym-ID' => $gym->id];
        $session = $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions", [
            'branch_id' => $branch->id,
            'title' => 'Attendance keeps capacity',
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHour()->toIso8601String(),
            'capacity' => 1,
            'waitlist_enabled' => true,
        ], $headers)->assertSuccessful()->json('data');

        $confirmed = $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions/{$session['id']}/bookings", [
            'member_id' => $confirmedMember->id,
        ], $headers)->assertJsonPath('data.status', 'booked')->json('data');
        $waitlisted = $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions/{$session['id']}/bookings", [
            'member_id' => $waitingMember->id,
        ], $headers)->assertJsonPath('data.status', 'waitlisted')->json('data');

        $this->postJson("/api/v1/gyms/{$gym->id}/class-bookings/{$confirmed['id']}/attend", [], $headers)
            ->assertOk()->assertJsonPath('data.status', 'attended');

        app(TenantContext::class)->run($gym, function () use ($session, $waitlisted): void {
            $storedSession = \App\Models\ClassSession::query()->findOrFail($session['id']);
            $storedWaitlist = ClassBooking::query()->findOrFail($waitlisted['id']);

            $this->assertSame(1, $storedSession->booked_count);
            $this->assertSame(1, $storedSession->waitlist_count);
            $this->assertSame(1, $storedSession->attended_count);
            $this->assertSame(ClassBookingStatus::Waitlisted, $storedWaitlist->status);
            $this->assertNull($storedWaitlist->promoted_at);
        });
    }

    public function test_no_show_is_rejected_before_the_class_starts(): void
    {
        [$owner, $gym, $branch] = $this->tenant();
        $member = app(TenantContext::class)->run($gym, fn () => $this->memberWithMembership($branch, 'MBR-FUTURE-NO-SHOW'));

        Sanctum::actingAs($owner);
        $headers = ['X-Gym-ID' => $gym->id];
        $session = $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions", [
            'branch_id' => $branch->id,
            'title' => 'Future no-show guard',
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHour()->toIso8601String(),
            'capacity' => 1,
            'waitlist_enabled' => true,
        ], $headers)->assertSuccessful()->json('data');
        $booking = $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions/{$session['id']}/bookings", [
            'member_id' => $member->id,
        ], $headers)->assertJsonPath('data.status', 'booked')->json('data');

        $this->postJson("/api/v1/gyms/{$gym->id}/class-bookings/{$booking['id']}/no-show", [], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('booking');

        app(TenantContext::class)->run($gym, function () use ($booking, $session): void {
            $storedSession = ClassSession::query()->findOrFail($session['id']);
            $this->assertSame(ClassBookingStatus::Booked, ClassBooking::query()->findOrFail($booking['id'])->status);
            $this->assertSame(1, $storedSession->booked_count);
            $this->assertSame(0, $storedSession->attended_count);
            $this->assertSame(0, $storedSession->waitlist_count);
        });
    }

    public function test_no_show_cannot_start_a_second_booked_lifecycle(): void
    {
        [$owner, $gym, $branch] = $this->tenant();
        $member = app(TenantContext::class)->run($gym, fn () => $this->memberWithMembership($branch, 'MBR-NO-SHOW-REBOOK'));

        Sanctum::actingAs($owner);
        $headers = ['X-Gym-ID' => $gym->id];
        $session = $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions", [
            'branch_id' => $branch->id,
            'title' => 'No-show booked lifecycle guard',
            'starts_at' => now()->addHour()->toIso8601String(),
            'ends_at' => now()->addHours(2)->toIso8601String(),
            'capacity' => 2,
            'waitlist_enabled' => true,
        ], $headers)->assertSuccessful()->json('data');
        $booking = $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions/{$session['id']}/bookings", [
            'member_id' => $member->id,
        ], $headers)->assertJsonPath('data.status', 'booked')->json('data');
        $this->moveClassToStarted($gym, $session['id']);

        $this->postJson("/api/v1/gyms/{$gym->id}/class-bookings/{$booking['id']}/no-show", [], $headers)
            ->assertOk()->assertJsonPath('data.status', 'no_show');
        $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions/{$session['id']}/bookings", [
            'member_id' => $member->id,
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('member_id');

        app(TenantContext::class)->run($gym, function () use ($session): void {
            $storedSession = ClassSession::query()->findOrFail($session['id']);
            $this->assertSame(1, ClassBooking::query()->count());
            $this->assertSame(1, $storedSession->booked_count);
            $this->assertSame(0, $storedSession->attended_count);
            $this->assertSame(0, $storedSession->waitlist_count);
        });
    }

    public function test_no_show_cannot_join_the_same_class_waitlist_again(): void
    {
        [$owner, $gym, $branch] = $this->tenant();
        $member = app(TenantContext::class)->run($gym, fn () => $this->memberWithMembership($branch, 'MBR-NO-SHOW-REWAIT'));

        Sanctum::actingAs($owner);
        $headers = ['X-Gym-ID' => $gym->id];
        $session = $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions", [
            'branch_id' => $branch->id,
            'title' => 'No-show waitlist lifecycle guard',
            'starts_at' => now()->addHour()->toIso8601String(),
            'ends_at' => now()->addHours(2)->toIso8601String(),
            'capacity' => 1,
            'waitlist_enabled' => true,
        ], $headers)->assertSuccessful()->json('data');
        $booking = $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions/{$session['id']}/bookings", [
            'member_id' => $member->id,
        ], $headers)->assertJsonPath('data.status', 'booked')->json('data');
        $this->moveClassToStarted($gym, $session['id']);

        $this->postJson("/api/v1/gyms/{$gym->id}/class-bookings/{$booking['id']}/no-show", [], $headers)
            ->assertOk()->assertJsonPath('data.status', 'no_show');
        $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions/{$session['id']}/bookings", [
            'member_id' => $member->id,
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('member_id');

        app(TenantContext::class)->run($gym, function () use ($session): void {
            $storedSession = ClassSession::query()->findOrFail($session['id']);
            $this->assertSame(1, ClassBooking::query()->count());
            $this->assertSame(1, $storedSession->booked_count);
            $this->assertSame(0, $storedSession->attended_count);
            $this->assertSame(0, $storedSession->waitlist_count);
        });
    }

    public function test_no_show_does_not_promote_the_fifo_waitlist(): void
    {
        [$owner, $gym, $branch] = $this->tenant();
        [$confirmedMember, $waitingMember] = app(TenantContext::class)->run($gym, fn (): array => [
            $this->memberWithMembership($branch, 'MBR-NO-SHOW-CONFIRMED'),
            $this->memberWithMembership($branch, 'MBR-NO-SHOW-WAITING'),
        ]);

        Sanctum::actingAs($owner);
        $headers = ['X-Gym-ID' => $gym->id];
        $session = $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions", [
            'branch_id' => $branch->id,
            'title' => 'No-show keeps FIFO position',
            'starts_at' => now()->addHour()->toIso8601String(),
            'ends_at' => now()->addHours(2)->toIso8601String(),
            'capacity' => 1,
            'waitlist_enabled' => true,
        ], $headers)->assertSuccessful()->json('data');
        $confirmed = $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions/{$session['id']}/bookings", [
            'member_id' => $confirmedMember->id,
        ], $headers)->assertJsonPath('data.status', 'booked')->json('data');
        $waitlisted = $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions/{$session['id']}/bookings", [
            'member_id' => $waitingMember->id,
        ], $headers)->assertJsonPath('data.status', 'waitlisted')->json('data');
        $this->moveClassToStarted($gym, $session['id']);

        $this->postJson("/api/v1/gyms/{$gym->id}/class-bookings/{$confirmed['id']}/no-show", [], $headers)
            ->assertOk()->assertJsonPath('data.status', 'no_show');

        app(TenantContext::class)->run($gym, function () use ($session, $waitlisted): void {
            $storedSession = ClassSession::query()->findOrFail($session['id']);
            $storedWaitlist = ClassBooking::query()->findOrFail($waitlisted['id']);
            $this->assertSame(1, $storedSession->booked_count);
            $this->assertSame(0, $storedSession->attended_count);
            $this->assertSame(1, $storedSession->waitlist_count);
            $this->assertSame(ClassBookingStatus::Waitlisted, $storedWaitlist->status);
            $this->assertNull($storedWaitlist->promoted_at);
        });
    }

    public function test_no_show_transition_remains_tenant_isolated(): void
    {
        [$firstOwner, $firstGym] = $this->tenant();
        [$secondOwner, $secondGym, $secondBranch] = $this->tenant();
        $member = app(TenantContext::class)->run($secondGym, fn () => $this->memberWithMembership($secondBranch, 'MBR-OTHER-NO-SHOW'));

        Sanctum::actingAs($secondOwner);
        $secondHeaders = ['X-Gym-ID' => $secondGym->id];
        $session = $this->postJson("/api/v1/gyms/{$secondGym->id}/class-sessions", [
            'branch_id' => $secondBranch->id,
            'title' => 'Tenant-owned no-show',
            'starts_at' => now()->addHour()->toIso8601String(),
            'ends_at' => now()->addHours(2)->toIso8601String(),
            'capacity' => 1,
            'waitlist_enabled' => false,
        ], $secondHeaders)->assertSuccessful()->json('data');
        $booking = $this->postJson("/api/v1/gyms/{$secondGym->id}/class-sessions/{$session['id']}/bookings", [
            'member_id' => $member->id,
        ], $secondHeaders)->assertJsonPath('data.status', 'booked')->json('data');
        $this->moveClassToStarted($secondGym, $session['id']);

        Sanctum::actingAs($firstOwner);
        $this->postJson("/api/v1/gyms/{$firstGym->id}/class-bookings/{$booking['id']}/no-show", [], [
            'X-Gym-ID' => $firstGym->id,
        ])->assertNotFound();

        app(TenantContext::class)->run($secondGym, fn () => $this->assertSame(
            ClassBookingStatus::Booked,
            ClassBooking::query()->findOrFail($booking['id'])->status,
        ));
    }

    public function test_postgresql_unique_index_protects_no_show_booking_lifecycles(): void
    {
        if ($this->app['db']->connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL partial-index enforcement is exercised on the PostgreSQL test gate.');
        }

        [$owner, $gym, $branch] = $this->tenant();
        $member = app(TenantContext::class)->run($gym, fn () => $this->memberWithMembership($branch, 'MBR-PG-NO-SHOW'));
        Sanctum::actingAs($owner);
        $headers = ['X-Gym-ID' => $gym->id];
        $session = $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions", [
            'branch_id' => $branch->id,
            'title' => 'PostgreSQL lifecycle guard',
            'starts_at' => now()->addHour()->toIso8601String(),
            'ends_at' => now()->addHours(2)->toIso8601String(),
            'capacity' => 2,
            'waitlist_enabled' => true,
        ], $headers)->assertSuccessful()->json('data');
        $booking = $this->postJson("/api/v1/gyms/{$gym->id}/class-sessions/{$session['id']}/bookings", [
            'member_id' => $member->id,
        ], $headers)->assertJsonPath('data.status', 'booked')->json('data');
        $this->moveClassToStarted($gym, $session['id']);
        $this->postJson("/api/v1/gyms/{$gym->id}/class-bookings/{$booking['id']}/no-show", [], $headers)
            ->assertOk()->assertJsonPath('data.status', 'no_show');

        $stored = app(TenantContext::class)->run($gym, fn () => ClassBooking::query()->findOrFail($booking['id']));
        $this->expectException(QueryException::class);
        app(TenantContext::class)->run($gym, fn () => ClassBooking::query()->create([
            'class_session_id' => $stored->class_session_id,
            'member_id' => $stored->member_id,
            'membership_id' => $stored->membership_id,
            'booked_by' => $owner->id,
            'status' => ClassBookingStatus::Booked,
            'booked_at' => now(),
        ]));
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

    private function moveClassToStarted(Gym $gym, string $sessionId): void
    {
        app(TenantContext::class)->run($gym, fn () => ClassSession::query()
            ->whereKey($sessionId)
            ->update([
                'starts_at' => now()->subMinute(),
                'ends_at' => now()->addHour(),
            ]));
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
