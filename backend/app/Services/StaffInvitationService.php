<?php

namespace App\Services;

use App\Enums\InvitationStatus;
use App\Enums\StaffStatus;
use App\Enums\UserRole;
use App\Jobs\SendAccountInvitation;
use App\Models\Gym;
use App\Models\StaffInvitation;
use App\Models\StaffProfile;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class StaffInvitationService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditService $audit,
    ) {}

    /** @return array{0: StaffInvitation, 1: string} */
    public function create(array $data, User $actor, Request $request): array
    {
        $this->ensureRoleCanBeGranted($actor, $data['role']);
        $email = mb_strtolower(trim($data['email']));
        $this->assertNoInvitationDuplicate($email, $data['employee_number']);

        if (StaffProfile::query()->where('contact_email', $email)
            ->orWhereHas('user', fn ($query) => $query->whereRaw('LOWER(email) = ?', [$email]))
            ->exists()) {
            throw ValidationException::withMessages([
                'email' => ['This email already belongs to a staff member in this gym.'],
            ]);
        }

        $plainToken = Str::random(64);
        $invitation = DB::transaction(function () use ($data, $actor, $request, $email, $plainToken): StaffInvitation {
            $invitation = StaffInvitation::query()->create([
                'home_branch_id' => $data['home_branch_id'] ?? null,
                'invited_by' => $actor->getKey(),
                'email' => $email,
                'role' => $data['role'],
                'employee_number' => $data['employee_number'],
                'job_title' => $data['job_title'] ?? null,
                // Only a SHA-256 hash is stored; the one-time token is returned once.
                'token_hash' => $this->tokenHash($this->context->id(), $plainToken),
                'status' => InvitationStatus::Pending,
                'expires_at' => now()->addDays((int) ($data['expires_in_days'] ?? 7)),
                'metadata' => array_merge($data['metadata'] ?? [], [
                    'delivery' => [
                        'event_type' => 'staff_invitation',
                        'status' => 'queued',
                        'updated_at' => now()->toIso8601String(),
                        'failure_reason' => null,
                    ],
                ]),
            ]);

            // The invitation and its audit evidence commit or roll back together.
            $this->audit->record(
                'staff.invited',
                $invitation,
                $actor,
                after: $invitation->toArray(),
                request: $request,
            );

            return $invitation;
        });

        $this->dispatchInvitation($invitation, $plainToken, 'staff_invitation');

        return [$invitation, $plainToken];
    }

    /** @return array{0: StaffInvitation, 1: string} */
    public function resend(StaffInvitation $invitation, User $actor, Request $request, int $expiresInDays = 7): array
    {
        $this->ensureRoleCanBeGranted($actor, $invitation->role->value);
        $plainToken = Str::random(64);
        $resent = DB::transaction(function () use ($invitation, $actor, $request, $plainToken, $expiresInDays): StaffInvitation {
            // The tenant scope and row lock prevent cross-gym token rotation and
            // concurrent resend/revoke races for the same pending invitation.
            $locked = StaffInvitation::query()->lockForUpdate()->findOrFail($invitation->getKey());
            if ($locked->status !== InvitationStatus::Pending) {
                throw ValidationException::withMessages(['invitation' => ['Only a pending invitation can be resent.']]);
            }

            $before = $locked->toArray();
            $locked->update([
                'invited_by' => $actor->getKey(),
                'token_hash' => $this->tokenHash($this->context->id(), $plainToken),
                'expires_at' => now()->addDays($expiresInDays),
                'metadata' => array_merge($locked->metadata ?? [], [
                    'delivery' => [
                        'event_type' => 'staff_invitation_resend',
                        'status' => 'queued',
                        'updated_at' => now()->toIso8601String(),
                        'failure_reason' => null,
                    ],
                ]),
            ]);
            $fresh = $locked->fresh();
            $this->audit->record('staff.invitation_resent', $fresh, $actor, $before, $fresh->toArray(), request: $request);

            return $fresh;
        });

        $this->dispatchInvitation($resent, $plainToken, 'staff_invitation_resend');

        return [$resent, $plainToken];
    }

    public function revoke(StaffInvitation $invitation, User $actor, Request $request): StaffInvitation
    {
        $this->ensureRoleCanBeGranted($actor, $invitation->role->value);

        return DB::transaction(function () use ($invitation, $actor, $request): StaffInvitation {
            $locked = StaffInvitation::query()->lockForUpdate()->findOrFail($invitation->getKey());
            if ($locked->status !== InvitationStatus::Pending) {
                throw ValidationException::withMessages(['invitation' => ['Only a pending invitation can be revoked.']]);
            }

            $before = $locked->toArray();
            $locked->update(['status' => InvitationStatus::Revoked]);
            $fresh = $locked->fresh();
            $this->audit->record('staff.invitation_revoked', $fresh, $actor, $before, $fresh->toArray(), request: $request);

            return $fresh;
        });
    }

    public function ensureRoleCanBeGranted(User $actor, string $targetRole): void
    {
        $actorRole = $actor->roleForGym($this->context->id());
        $isTenantAdministrator = in_array(
            $actorRole,
            [UserRole::SuperAdmin, UserRole::GymOwner],
            true,
        );

        // Managers can onboard operational staff but cannot create peers/owners.
        if (! $isTenantAdministrator && in_array($targetRole, [
            UserRole::GymOwner->value,
            UserRole::GymManager->value,
        ], true)) {
            throw new AuthorizationException('Only a gym owner can grant this role.');
        }
    }

    public function ensureProfileCanBeManaged(User $actor, string $currentRole): void
    {
        $actorRole = $actor->roleForGym($this->context->id());

        // Managers may administer operational staff, but must never suspend,
        // demote or otherwise mutate an owner or another manager in the tenant.
        if ($actorRole === UserRole::GymManager && in_array($currentRole, [
            UserRole::GymOwner->value,
            UserRole::GymManager->value,
        ], true)) {
            throw new AuthorizationException('Gym managers cannot modify owners or other managers.');
        }
    }

    private function assertNoInvitationDuplicate(string $email, string $employeeNumber): void
    {
        $pending = StaffInvitation::query()->where('status', InvitationStatus::Pending->value);
        if ((clone $pending)->where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => ['A pending invitation already uses this email.'],
            ]);
        }
        if ((clone $pending)->where('employee_number', $employeeNumber)->exists()) {
            throw ValidationException::withMessages([
                'employee_number' => ['A pending invitation already uses this employee number.'],
            ]);
        }
    }

    private function dispatchInvitation(StaffInvitation $invitation, string $plainToken, string $eventType): void
    {
        try {
            SendAccountInvitation::dispatch(
                $invitation->email,
                $this->context->id(),
                $this->context->gym()->name,
                $plainToken,
                'staff',
                $invitation->getKey(),
                $eventType,
            )->onQueue('notifications');
        } catch (Throwable) {
            // The invitation is already durable. A synchronous queue/provider
            // failure must not turn a successful create into a misleading 500.
            $invitation->update(['metadata' => array_merge($invitation->metadata ?? [], [
                'delivery' => [
                    'event_type' => $eventType,
                    'status' => 'failed',
                    'updated_at' => now()->toIso8601String(),
                    'failure_reason' => 'The invitation email could not be queued.',
                ],
            ])]);
            Log::warning('A staff invitation email could not be queued.', [
                'gym_id' => $this->context->id(),
                'invitation_id' => $invitation->getKey(),
            ]);
        }
    }

    /** @return array{gym_name: string, role: string, masked_email: string, existing_account: bool} */
    public function preview(Gym $gym, string $plainToken): array
    {
        return $this->context->run($gym, function () use ($gym, $plainToken): array {
            $invitation = $this->pendingInvitation($gym, $plainToken);

            return [
                'gym_name' => $gym->name,
                'role' => $invitation->role->value,
                'masked_email' => $this->maskEmail($invitation->email),
                'existing_account' => User::query()->whereRaw('LOWER(email) = ?', [$invitation->email])->exists(),
            ];
        });
    }

    public function accept(
        Gym $gym,
        string $plainToken,
        ?string $password,
        Request $request,
    ): User {
        return $this->context->run($gym, function () use ($gym, $plainToken, $password, $request): User {
            return DB::transaction(function () use ($gym, $plainToken, $password, $request): User {
                $invitation = $this->pendingInvitation($gym, $plainToken, true);
                $currentUser = $request->user();

                if ($currentUser && mb_strtolower($currentUser->email) !== $invitation->email) {
                    throw ValidationException::withMessages([
                        'token' => ['Sign out before activating an invitation for another account.'],
                    ]);
                }

                $user = User::query()->whereRaw('LOWER(email) = ?', [$invitation->email])
                    ->lockForUpdate()->first();
                if (! $user) {
                    if (! is_string($password) || mb_strlen($password) < 12) {
                        throw ValidationException::withMessages([
                            'password' => ['Create a password with at least 12 characters.'],
                        ]);
                    }

                    $localPart = explode('@', $invitation->email, 2)[0] ?? 'staff';
                    $user = User::query()->create([
                        'name' => Str::headline(str_replace(['.', '_', '-'], ' ', $localPart)) ?: 'Staff Member',
                        'email' => $invitation->email,
                        'password' => $password,
                    ]);
                }

                if ($user->platform_role !== null) {
                    throw ValidationException::withMessages([
                        'token' => ['A platform administrator account cannot be activated as gym staff.'],
                    ]);
                }

                $assignment = DB::table('gym_user')
                    ->where('gym_id', $gym->getKey())
                    ->where('user_id', $user->getKey())
                    ->lockForUpdate()
                    ->first();
                if ($assignment && $assignment->role !== $invitation->role->value) {
                    throw ValidationException::withMessages([
                        'token' => ['This account already has a different role in this gym.'],
                    ]);
                }

                $employeeNumberUsed = StaffProfile::query()
                    ->where('employee_number', $invitation->employee_number)
                    ->where('user_id', '<>', $user->getKey())
                    ->lockForUpdate()
                    ->exists();
                if ($employeeNumberUsed) {
                    throw ValidationException::withMessages([
                        'employee_number' => ['This employee number is already assigned in this gym.'],
                    ]);
                }

                $gym->users()->syncWithoutDetaching([
                    $user->getKey() => [
                        'role' => $invitation->role->value,
                        'status' => 'active',
                        'joined_at' => $assignment?->joined_at ?? now(),
                    ],
                ]);
                $gym->users()->updateExistingPivot($user->getKey(), [
                    'role' => $invitation->role->value,
                    'status' => 'active',
                    'joined_at' => $assignment?->joined_at ?? now(),
                ]);

                $profile = StaffProfile::query()->updateOrCreate(
                    ['user_id' => $user->getKey()],
                    [
                        'home_branch_id' => $invitation->home_branch_id,
                        'display_name' => $user->name,
                        'contact_email' => $user->email,
                        'employee_number' => $invitation->employee_number,
                        'job_title' => $invitation->job_title,
                        'status' => StaffStatus::Active,
                        'hired_at' => now()->toDateString(),
                        'terminated_at' => null,
                    ],
                );

                if ($invitation->home_branch_id) {
                    // Branch membership is written with the selected tenant key;
                    // a token can never attach staff to another gym's branch.
                    $profile->branches()->sync([
                        $invitation->home_branch_id => [
                            'gym_id' => $gym->getKey(),
                            'is_primary' => true,
                        ],
                    ]);
                } else {
                    $profile->branches()->sync([]);
                }

                $invitation->update([
                    'status' => InvitationStatus::Accepted,
                    'accepted_at' => now(),
                ]);

                $this->audit->record(
                    'staff.invitation_accepted',
                    $profile,
                    $user,
                    after: $profile->toArray(),
                    request: $request,
                );

                return $user;
            });
        });
    }

    private function pendingInvitation(Gym $gym, string $plainToken, bool $lock = false): StaffInvitation
    {
        $query = StaffInvitation::query()
            ->whereIn('token_hash', [
                $this->tokenHash($gym->getKey(), $plainToken),
                // Accept invitations created before tenant-bound token hashing.
                hash('sha256', $plainToken),
            ]);
        if ($lock) {
            $query->lockForUpdate();
        }
        $invitation = $query->first();

        if (! $invitation
            || $invitation->status !== InvitationStatus::Pending
            || ! $invitation->expires_at->isFuture()) {
            throw ValidationException::withMessages([
                'token' => ['The invitation is invalid or expired.'],
            ]);
        }

        return $invitation;
    }

    private function tokenHash(string $gymId, string $plainToken): string
    {
        return hash('sha256', mb_strtolower($gymId).'|'.$plainToken);
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);
        return mb_substr($local, 0, 1).str_repeat('*', max(3, mb_strlen($local) - 1)).'@'.$domain;
    }
}
