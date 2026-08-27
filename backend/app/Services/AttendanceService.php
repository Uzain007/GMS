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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    public function __construct(private readonly AuditService $audit) {}

    /** @return array{credential: MemberAccessCredential, plaintext: string, created: bool} */
    public function issueCredential(Member $member, array $data, User $actor, Request $request): array
    {
        return DB::transaction(function () use ($member, $data, $actor, $request): array {
            // A QR identity is useful only for a currently eligible member.
            // Branch access is still re-checked for every eventual scan.
            $this->activeMembershipForCredential($member);

            $existing = MemberAccessCredential::query()
                ->where('member_id', $member->getKey())
                ->where('status', AccessCredentialStatus::Active->value)
                ->lockForUpdate()
                ->latest()
                ->first();
            if ($existing && (! $existing->expires_at || $existing->expires_at->isFuture())) {
                $plaintext = $this->persistentPlaintext($existing);
                if ($plaintext !== null) {
                    return ['credential' => $existing, 'plaintext' => $plaintext, 'created' => false];
                }
            }

            return $this->createCredential($member, $data, $actor, $request);
        });
    }

    /** @return array{credential: MemberAccessCredential, plaintext: string, created: bool} */
    public function rotateCredential(Member $member, array $data, User $actor, Request $request): array
    {
        return DB::transaction(function () use ($member, $data, $actor, $request): array {
            $this->activeMembershipForCredential($member);
            return $this->createCredential($member, $data, $actor, $request);
        });
    }

    public function plaintextFor(MemberAccessCredential $credential): ?string
    {
        if (! str_starts_with($credential->credential_hint, 'v1:')) {
            return null;
        }

        $plaintext = $this->derivePersistentPlaintext(
            (string) $credential->getKey(),
            (string) $credential->gym_id,
            (string) $credential->member_id,
        );

        return hash_equals(
            (string) $credential->getRawOriginal('credential_hash'),
            hash('sha256', $plaintext),
        ) ? $plaintext : null;
    }

    /** @return array{credential: MemberAccessCredential, plaintext: string, created: bool} */
    private function createCredential(Member $member, array $data, User $actor, Request $request): array
    {
        MemberAccessCredential::query()
            ->where('member_id', $member->getKey())
            ->where('status', AccessCredentialStatus::Active->value)
            ->lockForUpdate()
            ->get()
            ->each->update([
                'status' => AccessCredentialStatus::Revoked,
                'revoked_at' => now(),
            ]);

        $credentialId = (string) Str::uuid();
        $plaintext = $this->derivePersistentPlaintext(
            $credentialId,
            (string) $member->gym_id,
            (string) $member->getKey(),
        );
        $credential = new MemberAccessCredential([
            'member_id' => $member->getKey(),
            'issued_by' => $actor->getKey(),
            'credential_hash' => hash('sha256', $plaintext),
            // The version marker is non-secret and lets authorised reads
            // distinguish reconstructable credentials from legacy one-time rows.
            'credential_hint' => 'v1:'.substr($plaintext, -8),
            'status' => AccessCredentialStatus::Active,
            'expires_at' => $data['expires_at'] ?? null,
        ]);
        // HasUuids normally assigns the key during creation. Persist the exact
        // server-generated key used by the HMAC so the secure QR can be
        // reconstructed after reload without storing its bearer plaintext.
        $credential->setAttribute($credential->getKeyName(), $credentialId);
        $credential->save();

        // Audit values intentionally exclude both plaintext and its digest.
        $this->audit->record('member.access_credential.issued', $credential, $actor, after: [
            'member_id' => $member->getKey(),
            'credential_hint' => $credential->credential_hint,
            'expires_at' => $credential->expires_at?->toIso8601String(),
        ], request: $request);

        return ['credential' => $credential, 'plaintext' => $plaintext, 'created' => true];
    }

    private function persistentPlaintext(MemberAccessCredential $credential): ?string
    {
        if ($credential->expires_at && $credential->expires_at->isPast()) {
            $credential->update(['status' => AccessCredentialStatus::Expired]);
            return null;
        }

        return $this->plaintextFor($credential);
    }

    private function derivePersistentPlaintext(string $credentialId, string $gymId, string $memberId): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            $key = $decoded !== false ? $decoded : $key;
        }
        if ($key === '') {
            throw new \RuntimeException('The application key is required for persistent QR credentials.');
        }

        // The database stores only SHA-256 evidence. A domain-separated HMAC
        // lets an authorised server reproduce the same opaque QR after reload
        // without persisting its bearer plaintext or exposing tenant/member IDs.
        $digest = hash_hmac(
            'sha256',
            implode('|', ['ironcore-member-qr-v1', $gymId, $memberId, $credentialId]),
            $key,
            true,
        );

        return 'icqr1_'.rtrim(strtr(base64_encode($digest), '+/', '-_'), '=');
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

    private function activeMembershipForCredential(Member $member): Membership
    {
        if ($member->status !== MemberStatus::Active) {
            throw ValidationException::withMessages(['member' => ['Only active members can create a gym pass.']]);
        }

        $membership = Membership::query()
            ->where('member_id', $member->getKey())
            ->where('status', MembershipStatus::Active->value)
            ->whereDate('starts_at', '<=', today())
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhereDate('ends_at', '>=', today()))
            ->lockForUpdate()
            ->first();
        if (! $membership) {
            throw ValidationException::withMessages(['membership' => ['An active, in-date membership is required to create a gym pass.']]);
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
            : Member::query()->where('member_code', $data['member_code'])->lockForUpdate()->first();
        if (! $member) {
            throw ValidationException::withMessages(['member_code' => ['No member matches this code in the selected gym.']]);
        }
        return [$member, null, isset($data['member_code']) ? AttendanceMethod::MemberCode : AttendanceMethod::Manual];
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
