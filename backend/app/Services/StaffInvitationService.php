<?php

namespace App\Services;

use App\Enums\InvitationStatus;
use App\Enums\StaffStatus;
use App\Enums\UserRole;
use App\Models\Gym;
use App\Models\StaffInvitation;
use App\Models\StaffProfile;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
        $email = mb_strtolower($data['email']);
        $duplicate = StaffInvitation::query()
            ->where('status', InvitationStatus::Pending->value)
            ->where(fn ($query) => $query
                ->where('email', $email)
                ->orWhere('employee_number', $data['employee_number']))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'email' => ['A pending invitation already uses this email or employee number.'],
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
                'token_hash' => hash('sha256', $plainToken),
                'status' => InvitationStatus::Pending,
                'expires_at' => now()->addDays((int) ($data['expires_in_days'] ?? 7)),
                'metadata' => $data['metadata'] ?? null,
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

        return [$invitation, $plainToken];
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

    public function accept(Gym $gym, User $user, string $plainToken, Request $request): StaffProfile
    {
        return $this->context->run($gym, function () use ($gym, $user, $plainToken, $request): StaffProfile {
            return DB::transaction(function () use ($gym, $user, $plainToken, $request): StaffProfile {
                // The caller supplies the gym plus an unguessable token; RLS is set
                // before the hash lookup, so the token cannot resolve another tenant.
                $invitation = StaffInvitation::query()
                    ->where('token_hash', hash('sha256', $plainToken))
                    ->where('status', InvitationStatus::Pending->value)
                    ->where('expires_at', '>', now())
                    ->first();

                if (! $invitation || mb_strtolower($user->email) !== $invitation->email) {
                    throw ValidationException::withMessages(['token' => ['The invitation is invalid or expired.']]);
                }

                $gym->users()->syncWithoutDetaching([
                    $user->getKey() => [
                        'role' => $invitation->role->value,
                        'status' => 'active',
                        'joined_at' => now(),
                    ],
                ]);
                $gym->users()->updateExistingPivot($user->getKey(), [
                    'role' => $invitation->role->value,
                    'status' => 'active',
                    'joined_at' => now(),
                ]);

                $profile = StaffProfile::query()->updateOrCreate(
                    ['user_id' => $user->getKey()],
                    [
                        'home_branch_id' => $invitation->home_branch_id,
                        'employee_number' => $invitation->employee_number,
                        'job_title' => $invitation->job_title,
                        'status' => StaffStatus::Active,
                        'hired_at' => now()->toDateString(),
                    ],
                );

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

                return $profile->load('user');
            });
        });
    }
}
