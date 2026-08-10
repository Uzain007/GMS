<?php

namespace App\Services;

use App\Enums\ClassBookingStatus;
use App\Enums\ClassSessionStatus;
use App\Enums\UserRole;
use App\Models\ClassBooking;
use App\Models\ClassSession;
use App\Models\Member;
use App\Models\StaffProfile;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Carbon\CarbonImmutable;

class ClassBookingService
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly AuditService $audit,
        private readonly TenantContext $tenant,
    ) {}

    public function createSession(array $data, User $actor, Request $request): ClassSession
    {
        return DB::transaction(function () use ($data, $actor, $request): ClassSession {
            $session = ClassSession::query()->create([
                ...$data,
                'created_by' => $actor->getKey(),
                'status' => ClassSessionStatus::Scheduled,
            ]);
            $this->audit->record('class_session.created', $session, $actor, after: $session->toArray(), request: $request);
            return $session->load(['branch', 'trainer.user']);
        });
    }

    public function updateSession(string $sessionId, array $data, User $actor, Request $request): ClassSession
    {
        return DB::transaction(function () use ($sessionId, $data, $actor, $request): ClassSession {
            $session = ClassSession::query()->lockForUpdate()->findOrFail($sessionId);
            $before = $session->toArray();
            if (isset($data['capacity']) && $data['capacity'] < $session->booked_count) {
                throw ValidationException::withMessages(['capacity' => ['Capacity cannot be lower than the confirmed booking count.']]);
            }

            $reason = $data['reason'] ?? null;
            unset($data['reason']);
            if (($data['status'] ?? null) === ClassSessionStatus::Cancelled->value) {
                ClassBooking::query()->where('class_session_id', $session->getKey())
                    ->whereIn('status', [ClassBookingStatus::Booked->value, ClassBookingStatus::Waitlisted->value])
                    ->update([
                        'status' => ClassBookingStatus::Cancelled->value,
                        'cancelled_at' => now(),
                        'cancellation_reason' => 'Class cancelled: '.$reason,
                    ]);
                $data['cancellation_reason'] = $reason;
                $data['booked_count'] = 0;
                $data['waitlist_count'] = 0;
            }

            $startsAt = CarbonImmutable::parse($data['starts_at'] ?? $session->starts_at);
            $endsAt = CarbonImmutable::parse($data['ends_at'] ?? $session->ends_at);
            if ($endsAt->lessThanOrEqualTo($startsAt)) {
                throw ValidationException::withMessages(['ends_at' => ['The class end must be after its start.']]);
            }

            $session->update($data);
            $fresh = $session->fresh(['branch', 'trainer.user']);
            $this->audit->record('class_session.updated', $fresh, $actor, $before, $fresh->toArray(), $reason, $request);
            return $fresh;
        });
    }

    public function book(string $sessionId, ?string $memberId, User $actor, Request $request): ClassBooking
    {
        return DB::transaction(function () use ($sessionId, $memberId, $actor, $request): ClassBooking {
            $session = ClassSession::query()->lockForUpdate()->findOrFail($sessionId);
            $this->assertBookable($session);
            $member = $this->memberForActor($memberId, $actor);
            $membership = $this->attendance->activeMembershipFor($member, $session->branch_id);

            if (ClassBooking::query()->where('class_session_id', $session->getKey())
                ->where('member_id', $member->getKey())
                ->whereIn('status', [ClassBookingStatus::Booked->value, ClassBookingStatus::Waitlisted->value, ClassBookingStatus::Attended->value])
                ->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['member_id' => ['This member already has an active booking for the class.']]);
            }

            $waitlistSequence = null;
            if ($session->booked_count < $session->capacity) {
                $status = ClassBookingStatus::Booked;
                $session->booked_count++;
            } else {
                if (! $session->waitlist_enabled) {
                    throw ValidationException::withMessages(['class_session' => ['This class is full and its waitlist is closed.']]);
                }
                $status = ClassBookingStatus::Waitlisted;
                $waitlistSequence = $session->next_waitlist_sequence++;
                $session->waitlist_count++;
            }
            $session->save();

            $booking = ClassBooking::query()->create([
                'class_session_id' => $session->getKey(),
                'member_id' => $member->getKey(),
                'membership_id' => $membership->getKey(),
                'booked_by' => $actor->getKey(),
                'status' => $status,
                'waitlist_sequence' => $waitlistSequence,
                'booked_at' => now(),
            ]);
            $this->audit->record('class_booking.created', $booking, $actor, after: [
                'class_session_id' => $session->getKey(), 'member_id' => $member->getKey(), 'status' => $status->value,
            ], request: $request);
            return $booking->load(['member', 'session']);
        });
    }

    public function cancel(string $bookingId, string $reason, User $actor, Request $request): ClassBooking
    {
        return DB::transaction(function () use ($bookingId, $reason, $actor, $request): ClassBooking {
            $bookingKey = ClassBooking::query()->findOrFail($bookingId);
            $session = ClassSession::query()->lockForUpdate()->findOrFail($bookingKey->class_session_id);
            $booking = ClassBooking::query()->lockForUpdate()->findOrFail($bookingId);
            $this->assertBookingActor($booking, $actor);
            if (! in_array($booking->status, [ClassBookingStatus::Booked, ClassBookingStatus::Waitlisted], true)) {
                throw ValidationException::withMessages(['booking' => ['Only booked or waitlisted entries can be cancelled.']]);
            }

            $wasBooked = $booking->status === ClassBookingStatus::Booked;
            $booking->update([
                'status' => ClassBookingStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            if ($wasBooked) {
                $session->booked_count = max(0, $session->booked_count - 1);
                $promoted = ClassBooking::query()->where('class_session_id', $session->getKey())
                    ->where('status', ClassBookingStatus::Waitlisted->value)
                    ->orderBy('waitlist_sequence')->lockForUpdate()->first();
                if ($promoted) {
                    $promoted->update(['status' => ClassBookingStatus::Booked, 'promoted_at' => now()]);
                    $session->booked_count++;
                    $session->waitlist_count = max(0, $session->waitlist_count - 1);
                    $this->audit->record('class_booking.promoted', $promoted, $actor, after: [
                        'class_session_id' => $session->getKey(), 'member_id' => $promoted->member_id,
                    ], request: $request);
                }
            } else {
                $session->waitlist_count = max(0, $session->waitlist_count - 1);
            }
            $session->save();

            $this->audit->record('class_booking.cancelled', $booking, $actor, after: [
                'class_session_id' => $session->getKey(), 'member_id' => $booking->member_id, 'reason' => $reason,
            ], reason: $reason, request: $request);
            return $booking->fresh(['member', 'session']);
        });
    }

    public function attend(string $bookingId, User $actor, Request $request): ClassBooking
    {
        return DB::transaction(function () use ($bookingId, $actor, $request): ClassBooking {
            $bookingKey = ClassBooking::query()->findOrFail($bookingId);
            $session = ClassSession::query()->lockForUpdate()->findOrFail($bookingKey->class_session_id);
            $booking = ClassBooking::query()->lockForUpdate()->findOrFail($bookingId);
            $this->assertAttendanceActor($session, $actor);
            if ($booking->status !== ClassBookingStatus::Booked) {
                throw ValidationException::withMessages(['booking' => ['Only a confirmed booking can be marked attended.']]);
            }

            $member = Member::query()->lockForUpdate()->findOrFail($booking->member_id);
            $membership = $this->attendance->activeMembershipFor($member, $session->branch_id);
            $this->attendance->ensureClassPresence($member, $membership, $session->branch_id, $actor);
            $booking->update(['status' => ClassBookingStatus::Attended, 'checked_in_at' => now()]);
            $session->attended_count++;
            $session->save();

            $this->audit->record('class_booking.attended', $booking, $actor, after: [
                'class_session_id' => $session->getKey(), 'member_id' => $member->getKey(),
            ], request: $request);
            return $booking->fresh(['member', 'session']);
        });
    }

    public function assertRosterActor(ClassSession $session, User $actor): void
    {
        if ($actor->roleForGym($this->tenant->id()) !== UserRole::Trainer) {
            return;
        }
        $trainer = StaffProfile::query()->where('user_id', $actor->getKey())->first();
        if (! $trainer || $session->trainer_staff_profile_id !== $trainer->getKey()) {
            abort(403, 'Trainers may access only their assigned class roster.');
        }
    }

    private function memberForActor(?string $memberId, User $actor): Member
    {
        if ($actor->roleForGym($this->tenant->id()) === UserRole::Member) {
            $member = Member::query()->where('user_id', $actor->getKey())->lockForUpdate()->firstOrFail();
            if ($memberId && $memberId !== $member->getKey()) {
                abort(403, 'Members may book only for themselves.');
            }
            return $member;
        }
        if (! $memberId) {
            throw ValidationException::withMessages(['member_id' => ['Select a member for this booking.']]);
        }
        return Member::query()->lockForUpdate()->findOrFail($memberId);
    }

    private function assertBookingActor(ClassBooking $booking, User $actor): void
    {
        if ($actor->roleForGym($this->tenant->id()) !== UserRole::Member) {
            return;
        }
        $member = Member::query()->where('user_id', $actor->getKey())->firstOrFail();
        if ($booking->member_id !== $member->getKey()) {
            abort(403, 'Members may cancel only their own booking.');
        }
    }

    private function assertAttendanceActor(ClassSession $session, User $actor): void
    {
        $this->assertRosterActor($session, $actor);
    }

    private function assertBookable(ClassSession $session): void
    {
        if ($session->status !== ClassSessionStatus::Scheduled || $session->ends_at->isPast()) {
            throw ValidationException::withMessages(['class_session' => ['This class is not open for booking.']]);
        }
        if ($session->booking_opens_at && now()->isBefore($session->booking_opens_at)) {
            throw ValidationException::withMessages(['class_session' => ['Booking has not opened yet.']]);
        }
        if ($session->booking_closes_at && now()->isAfter($session->booking_closes_at)) {
            throw ValidationException::withMessages(['class_session' => ['Booking is closed.']]);
        }
    }
}
