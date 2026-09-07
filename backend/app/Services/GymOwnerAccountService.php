<?php

namespace App\Services;

use App\Enums\StaffStatus;
use App\Enums\UserRole;
use App\Jobs\SendPasswordResetLink;
use App\Models\Gym;
use App\Models\StaffProfile;
use App\Models\User;
use App\Support\DatabaseIdentityContext;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GymOwnerAccountService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditService $audit,
        private readonly DatabaseIdentityContext $identity,
    ) {}

    /** @return array<string, mixed>|null */
    public function current(): ?array
    {
        $pivot = DB::table('gym_user')
            ->where('gym_id', $this->tenant->id())
            ->where('role', UserRole::GymOwner->value)
            ->first();

        if ($pivot === null) {
            return null;
        }

        $user = User::query()->findOrFail($pivot->user_id);
        $profile = StaffProfile::query()->where('user_id', $user->getKey())->first();

        return $this->payload($user, $profile, $pivot);
    }

    /** @return array<string, mixed> */
    public function create(array $data, User $actor, Request $request): array
    {
        if (DB::table('gym_user')->where('gym_id', $this->tenant->id())->where('role', UserRole::GymOwner->value)->exists()) {
            throw ValidationException::withMessages(['owner' => ['This gym already has an owner login account.']]);
        }

        $email = mb_strtolower($data['email']);
        $sendInvite = $data['setup_method'] === 'invite';

        $payload = DB::transaction(function () use ($data, $actor, $request, $email, $sendInvite): array {
            $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();
            $existing = $user !== null;

            if ($user?->isSuperAdmin()) {
                throw ValidationException::withMessages(['email' => ['A platform administrator cannot be assigned as a gym owner.']]);
            }
            if ($existing && $data['setup_method'] === 'temporary_password') {
                throw ValidationException::withMessages([
                    'email' => ['This email already has an IronCore account. Use the secure invite option so its password is not replaced.'],
                ]);
            }

            if (! $user) {
                $user = User::query()->create([
                    'name' => $data['name'],
                    'email' => $email,
                    'password' => $data['setup_method'] === 'temporary_password'
                        ? $data['temporary_password']
                        : Str::random(64),
                    'must_change_password' => true,
                ]);
            }

            $now = now();
            DB::table('gym_user')->insert([
                'gym_id' => $this->tenant->id(),
                'user_id' => $user->getKey(),
                'role' => UserRole::GymOwner->value,
                'status' => 'active',
                'setup_method' => $existing ? 'existing_account' : $data['setup_method'],
                'joined_at' => $now,
                'invite_sent_at' => $sendInvite ? $now : null,
                'setup_completed_at' => $existing ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $profile = StaffProfile::query()->create([
                'user_id' => $user->getKey(),
                'display_name' => $data['name'],
                'contact_email' => $email,
                'phone' => $data['phone'],
                'employee_number' => $this->nextOwnerNumber(),
                'job_title' => 'Gym Owner',
                'status' => StaffStatus::Active,
                'hired_at' => $now->toDateString(),
            ]);

            $pivot = DB::table('gym_user')
                ->where('gym_id', $this->tenant->id())
                ->where('user_id', $user->getKey())
                ->first();
            $account = $this->payload($user, $profile, $pivot);
            $this->audit->record(
                'gym.owner_account.created',
                $profile,
                $actor,
                after: $this->auditSnapshot($account),
                reason: $data['reason'] ?? null,
                request: $request,
            );

            return $account;
        });

        if ($sendInvite) {
            // The queue job creates and mails the broker token after commit, so
            // no setup secret is persisted in the request, response or audit.
            SendPasswordResetLink::dispatch($email)->afterCommit();
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function update(array $data, User $actor, Request $request): array
    {
        return DB::transaction(function () use ($data, $actor, $request): array {
            [$user, $profile, $pivot] = $this->lockedAccount();
            $before = $this->payload($user, $profile, $pivot);
            $email = mb_strtolower($data['email']);
            $emailChanged = $email !== mb_strtolower($user->email);

            if ($emailChanged && User::query()->whereRaw('LOWER(email) = ?', [$email])->whereKeyNot($user->getKey())->exists()) {
                throw ValidationException::withMessages(['email' => ['That email is already used by another IronCore account.']]);
            }

            $user->forceFill([
                'name' => $data['name'],
                'email' => $email,
                'auth_version' => $emailChanged ? $user->auth_version + 1 : $user->auth_version,
                'remember_token' => $emailChanged ? Str::random(60) : $user->remember_token,
            ])->save();
            $profile->update([
                'display_name' => $data['name'],
                'contact_email' => $email,
                'phone' => $data['phone'],
                'status' => $data['status'] === 'active' ? StaffStatus::Active : StaffStatus::Suspended,
                'terminated_at' => $data['status'] === 'active' ? null : now()->toDateString(),
            ]);
            DB::table('gym_user')
                ->where('gym_id', $this->tenant->id())
                ->where('user_id', $user->getKey())
                ->update(['status' => $data['status'], 'updated_at' => now()]);

            if ($emailChanged) {
                $user->tokens()->delete();
                DB::table('sessions')->where('user_id', $user->getKey())->delete();
                DB::table((string) config('auth.passwords.users.table', 'password_reset_tokens'))
                    ->where('email', $before['email'])->delete();
            }

            $freshPivot = DB::table('gym_user')->where('gym_id', $this->tenant->id())->where('user_id', $user->getKey())->first();
            $after = $this->payload($user->fresh(), $profile->fresh(), $freshPivot);
            $this->audit->record('gym.owner_account.updated', $profile, $actor, $this->auditSnapshot($before), $this->auditSnapshot($after), $data['reason'], $request);

            return $after;
        });
    }

    /** @return array<string, mixed> */
    public function sendResetLink(string $reason, User $actor, Request $request): array
    {
        [$user, $profile] = $this->account();
        DB::table('gym_user')
            ->where('gym_id', $this->tenant->id())
            ->where('user_id', $user->getKey())
            ->update(['invite_sent_at' => now(), 'updated_at' => now()]);
        $this->audit->record('gym.owner_account.reset_link_sent', $profile, $actor, after: ['user_id' => $user->getKey()], reason: $reason, request: $request);
        SendPasswordResetLink::dispatch($user->email)->afterCommit();

        return $this->current();
    }

    /** @return array{account: array<string, mixed>, temporary_password: string} */
    public function generateTemporaryPassword(string $reason, User $actor, Request $request): array
    {
        return DB::transaction(function () use ($reason, $actor, $request): array {
            [$user, $profile] = $this->lockedAccount();
            $temporary = 'Ic!'.Str::upper(Str::random(3)).Str::lower(Str::random(8)).random_int(1000, 9999);
            $user->forceFill([
                'password' => $temporary,
                'must_change_password' => true,
                'remember_token' => Str::random(60),
                'auth_version' => $user->auth_version + 1,
            ])->save();
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->getKey())->delete();
            DB::table((string) config('auth.passwords.users.table', 'password_reset_tokens'))->where('email', $user->email)->delete();
            DB::table('gym_user')->where('gym_id', $this->tenant->id())->where('user_id', $user->getKey())->update([
                'setup_method' => 'temporary_password',
                'setup_completed_at' => null,
                'updated_at' => now(),
            ]);
            $this->audit->record(
                'gym.owner_account.temporary_password_generated',
                $profile,
                $actor,
                after: ['user_id' => $user->getKey(), 'must_change_password' => true],
                reason: $reason,
                request: $request,
            );

            return ['account' => $this->current(), 'temporary_password' => $temporary];
        });
    }

    public function markSetupCompleted(User $user): void
    {
        $gymIds = $this->identity->run($user, fn () => DB::table('gym_user')
            ->where('user_id', $user->getKey())
            ->where('role', UserRole::GymOwner->value)
            ->whereNull('setup_completed_at')
            ->pluck('gym_id')->all());

        foreach (Gym::query()->whereIn('id', $gymIds)->get() as $gym) {
            $this->tenant->run($gym, fn () => DB::table('gym_user')
                ->where('gym_id', $gym->getKey())
                ->where('user_id', $user->getKey())
                ->update(['setup_completed_at' => now(), 'updated_at' => now()]));
        }
    }

    /** @return array{0: User, 1: StaffProfile|null, 2: object} */
    private function account(): array
    {
        $pivot = DB::table('gym_user')->where('gym_id', $this->tenant->id())->where('role', UserRole::GymOwner->value)->first();
        if ($pivot === null) {
            throw ValidationException::withMessages(['owner' => ['This gym does not have an owner login account yet.']]);
        }
        $user = User::query()->findOrFail($pivot->user_id);
        $profile = StaffProfile::query()->where('user_id', $user->getKey())->first();

        return [$user, $profile, $pivot];
    }

    /** @return array{0: User, 1: StaffProfile, 2: object} */
    private function lockedAccount(): array
    {
        $pivot = DB::table('gym_user')->where('gym_id', $this->tenant->id())->where('role', UserRole::GymOwner->value)->lockForUpdate()->first();
        if ($pivot === null) {
            throw ValidationException::withMessages(['owner' => ['This gym does not have an owner login account yet.']]);
        }

        $user = User::query()->lockForUpdate()->findOrFail($pivot->user_id);
        $profile = StaffProfile::query()->where('user_id', $pivot->user_id)->lockForUpdate()->first();
        if (! $profile) {
            // Older gyms predate the explicit owner profile. Create the missing
            // tenant-owned contact row only inside the selected RLS context.
            $profile = StaffProfile::query()->create([
                'user_id' => $user->getKey(),
                'display_name' => $user->name,
                'contact_email' => $user->email,
                'employee_number' => $this->nextOwnerNumber(),
                'job_title' => 'Gym Owner',
                'status' => $pivot->status === 'active' ? StaffStatus::Active : StaffStatus::Suspended,
            ]);
        }

        return [$user, $profile, $pivot];
    }

    private function nextOwnerNumber(): string
    {
        do {
            $number = 'OWNER-'.Str::upper(Str::random(10));
        } while (StaffProfile::query()->where('employee_number', $number)->exists());

        return $number;
    }

    /** @return array<string, mixed> */
    private function payload(User $user, ?StaffProfile $profile, object $pivot): array
    {
        $setupStatus = $user->must_change_password
            ? (($pivot->setup_method ?? null) === 'invite' ? 'invite_pending' : 'password_change_required')
            : 'complete';

        return [
            'user_id' => $user->getKey(),
            'name' => $profile?->professionalName() ?? $user->name,
            'email' => $user->email,
            'phone' => $profile?->phone,
            'account_status' => $pivot->status,
            'setup_status' => $setupStatus,
            'setup_method' => $pivot->setup_method,
            'invite_sent_at' => $pivot->invite_sent_at,
            'setup_completed_at' => $pivot->setup_completed_at,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'must_change_password' => (bool) $user->must_change_password,
            'role' => UserRole::GymOwner->value,
        ];
    }

    /** @param array<string, mixed> $account */
    private function auditSnapshot(array $account): array
    {
        return collect($account)->except(['last_login_at'])->all();
    }
}
