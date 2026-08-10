<?php

namespace App\Services;

use App\Enums\AccessCredentialStatus;
use App\Enums\AttendanceMethod;
use App\Enums\AttendanceStatus;
use App\Enums\MemberStatus;
use App\Enums\MembershipStatus;
use App\Models\AttendanceRecord;
use App\Models\Member;
use App\Models\MemberAccessCredential;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    public function __construct(private readonly AuditService $audit) {}

    /** @return array{credential: MemberAccessCredential, plaintext: string} */
    public function issueCredential(Member $member, array $data, User $actor, Request $request): array
    {
        return DB::transaction(function () use ($member, $data, $actor, $request): array {
            MemberAccessCredential::query()
                ->where('member_id', $member->getKey())
                ->where('status', AccessCredentialStatus::Active->value)
                ->lockForUpdate()
                ->get()
                ->each->update([
                    'status' => AccessCredentialStatus::Revoked,
                    'revoked_at' => now(),
                ]);

            $plaintext = 'icqr_'.bin2hex(random_bytes(32));
            $credential = MemberAccessCredential::query()->create([
                'member_id' => $member->getKey(),
                'issued_by' => $actor->getKey(),
                'credential_hash' => hash('sha256', $plaintext),
                'credential_hint' => substr($plaintext, -8),
                'status' => AccessCredentialStatus::Active,
                'expires_at' => $data['expires_at'] ?? null,
            ]);

            // Audit values intentionally exclude both plaintext and its digest.
            $this->audit->record('member.access_credential.issued', $credential, $actor, after: [
                'member_id' => $member->getKey(),
                'credential_hint' => $credential->credential_hint,
                'expires_at' => $credential->expires_at?->toIso8601String(),
            ], request: $request);

            return ['credential' => $credential, 'plaintext' => $plaintext];
        });
    }

    public function checkIn(array $data, User $actor, Request $request): AttendanceRecord
    {
        return DB::transaction(function () use ($data, $actor, $request): AttendanceRecord {
            [$member, $credential, $method] = $this->resolveMember($data);
            $membership = $this->activeMembershipFor($member, $data['branch_id']);

            if (AttendanceRecord::query()
                ->where('member_id', $member->getKey())
                ->where('status', AttendanceStatus::CheckedIn->value)
                ->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['member' => ['This member is already checked in.']]);
            }

            $attendance = $this->createPresence(
                $member,
                $membership,
                $data['branch_id'],
                $actor,
                $method,
                $credential,
            );

            if ($credential) {
                $credential->update(['last_used_at' => now()]);
            }

            $this->audit->record('attendance.checked_in', $attendance, $actor, after: [
                'member_id' => $member->getKey(),
                'branch_id' => $data['branch_id'],
                'method' => $method->value,
                'checked_in_at' => $attendance->checked_in_at->toIso8601String(),
            ], request: $request);

            return $attendance->load(['member', 'branch']);
        });
    }

    public function checkOut(string $attendanceId, User $actor, Request $request): AttendanceRecord
    {
        return DB::transaction(function () use ($attendanceId, $actor, $request): AttendanceRecord {
            $attendance = AttendanceRecord::query()->lockForUpdate()->findOrFail($attendanceId);
            if ($attendance->status !== AttendanceStatus::CheckedIn) {
                throw ValidationException::withMessages(['attendance' => ['This attendance record is already closed.']]);
            }

            $attendance->update([
                'status' => AttendanceStatus::CheckedOut,
                'checked_out_by' => $actor->getKey(),
                'checked_out_at' => now(),
            ]);
            $this->audit->record('attendance.checked_out', $attendance, $actor, after: [
                'member_id' => $attendance->member_id,
                'branch_id' => $attendance->branch_id,
                'checked_out_at' => $attendance->checked_out_at?->toIso8601String(),
            ], request: $request);

            return $attendance->load(['member', 'branch']);
        });
    }

    public function activeMembershipFor(Member $member, string $branchId): Membership
    {
        if ($member->status !== MemberStatus::Active) {
            throw ValidationException::withMessages(['member' => ['Only active members can check in or book classes.']]);
        }

        $membership = Membership::query()
            ->where('member_id', $member->getKey())
            ->where('status', MembershipStatus::Active->value)
            ->whereDate('starts_at', '<=', today())
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhereDate('ends_at', '>=', today()))
            ->lockForUpdate()
            ->first();
        if (! $membership) {
            throw ValidationException::withMessages(['membership' => ['An active, in-date membership is required.']]);
        }
        if ($membership->branch_id && $membership->branch_id !== $branchId) {
            throw ValidationException::withMessages(['branch_id' => ['This membership is not valid at the selected branch.']]);
        }

        return $membership;
    }

    public function ensureClassPresence(Member $member, Membership $membership, string $branchId, User $actor): AttendanceRecord
    {
        $existing = AttendanceRecord::query()
            ->where('member_id', $member->getKey())
            ->where('status', AttendanceStatus::CheckedIn->value)
            ->lockForUpdate()->first();
        if ($existing) {
            if ($existing->branch_id !== $branchId) {
                throw ValidationException::withMessages(['attendance' => ['The member is currently checked in at another branch.']]);
            }
            return $existing;
        }

        return $this->createPresence($member, $membership, $branchId, $actor, AttendanceMethod::Manual);
    }

    /** @return array{Member, ?MemberAccessCredential, AttendanceMethod} */
    private function resolveMember(array $data): array
    {
        if (! empty($data['credential'])) {
            $credential = MemberAccessCredential::query()
                ->where('credential_hash', hash('sha256', $data['credential']))
                ->where('status', AccessCredentialStatus::Active->value)
                ->lockForUpdate()->first();
            if (! $credential || ($credential->expires_at && $credential->expires_at->isPast())) {
                if ($credential) {
                    $credential->update(['status' => AccessCredentialStatus::Expired]);
                }
                throw ValidationException::withMessages(['credential' => ['The QR credential is invalid or expired.']]);
            }
            return [Member::query()->lockForUpdate()->findOrFail($credential->member_id), $credential, AttendanceMethod::Qr];
        }

        $member = isset($data['member_id'])
            ? Member::query()->lockForUpdate()->findOrFail($data['member_id'])
            : Member::query()->where('member_number', $data['member_number'])->lockForUpdate()->first();
        if (! $member) {
            throw ValidationException::withMessages(['member_number' => ['No member matches this code in the selected gym.']]);
        }
        return [$member, null, isset($data['member_number']) ? AttendanceMethod::MemberCode : AttendanceMethod::Manual];
    }

    private function createPresence(
        Member $member,
        Membership $membership,
        string $branchId,
        User $actor,
        AttendanceMethod $method,
        ?MemberAccessCredential $credential = null,
    ): AttendanceRecord {
        return AttendanceRecord::query()->create([
            'member_id' => $member->getKey(),
            'membership_id' => $membership->getKey(),
            'branch_id' => $branchId,
            'access_credential_id' => $credential?->getKey(),
            'checked_in_by' => $actor->getKey(),
            'method' => $method,
            'status' => AttendanceStatus::CheckedIn,
            'checked_in_at' => now(),
        ]);
    }
}
