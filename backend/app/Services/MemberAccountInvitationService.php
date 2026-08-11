<?php

namespace App\Services;

use App\Enums\InvitationStatus;
use App\Enums\UserRole;
use App\Models\Gym;
use App\Models\Member;
use App\Models\MemberAccountInvitation;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MemberAccountInvitationService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditService $audit,
    ) {}

    /** @return array{0: MemberAccountInvitation, 1: string} */
    public function create(string $memberId, int $expiresInHours, User $actor, Request $request): array
    {
        $plainToken = Str::random(64);

        return DB::transaction(function () use ($memberId, $expiresInHours, $actor, $request, $plainToken): array {
            $member = Member::query()->lockForUpdate()->findOrFail($memberId);
            $email = mb_strtolower(trim((string) $member->email));

            if ($member->user_id) {
                throw ValidationException::withMessages([
                    'member' => ['This member already has a portal account.'],
                ]);
            }
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw ValidationException::withMessages([
                    'member' => ['A valid member email is required before sending an invitation.'],
                ]);
            }

            // Reissuing invalidates the previous secret inside the same member
            // lock, so only one usable invitation can exist for this account.
            MemberAccountInvitation::query()
                ->where('member_id', $member->getKey())
                ->where('status', InvitationStatus::Pending->value)
                ->lockForUpdate()
                ->update([
                    'status' => InvitationStatus::Revoked->value,
                    'revoked_at' => now(),
                ]);

            $invitation = MemberAccountInvitation::query()->create([
                'member_id' => $member->getKey(),
                'invited_by' => $actor->getKey(),
                'email' => $email,
                // The route tenant participates in the digest and RLS is bound
                // before every lookup. The plaintext value is returned once.
                'token_hash' => $this->tokenHash($this->context->id(), $plainToken),
                'status' => InvitationStatus::Pending,
                'expires_at' => now()->addHours($expiresInHours),
            ]);

            $this->audit->record(
                'member.account_invited',
                $member,
                $actor,
                after: [
                    'invitation_id' => $invitation->getKey(),
                    'email' => $email,
                    'expires_at' => $invitation->expires_at->toIso8601String(),
                ],
                request: $request,
            );

            return [$invitation, $plainToken];
        });
    }

    /** @return array{gym_name: string, member_first_name: string, masked_email: string, existing_account: bool} */
    public function preview(Gym $gym, string $plainToken): array
    {
        return $this->context->run($gym, function () use ($gym, $plainToken): array {
            $invitation = $this->pendingInvitation($gym, $plainToken);
            $member = Member::query()->findOrFail($invitation->member_id);

            if ($member->user_id || mb_strtolower(trim((string) $member->email)) !== $invitation->email) {
                $this->invalidToken();
            }

            return [
                'gym_name' => $gym->name,
                'member_first_name' => $member->first_name,
                'masked_email' => $this->maskEmail($invitation->email),
                'existing_account' => User::query()->where('email', $invitation->email)->exists(),
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
                $invitation = MemberAccountInvitation::query()
                    ->where('token_hash', $this->tokenHash($gym->getKey(), $plainToken))
                    ->lockForUpdate()
                    ->first();

                if (! $this->isUsable($invitation)) {
                    $this->invalidToken();
                }

                $member = Member::query()->lockForUpdate()->findOrFail($invitation->member_id);
                if ($member->user_id || mb_strtolower(trim((string) $member->email)) !== $invitation->email) {
                    $this->invalidToken();
                }

                $currentUser = $request->user();
                if ($currentUser && mb_strtolower($currentUser->email) !== $invitation->email) {
                    throw ValidationException::withMessages([
                        'token' => ['Sign out before activating an invitation for another account.'],
                    ]);
                }

                $user = User::query()->where('email', $invitation->email)->lockForUpdate()->first();
                if (! $user) {
                    if (! is_string($password) || mb_strlen($password) < 12) {
                        throw ValidationException::withMessages([
                            'password' => ['Create a password with at least 12 characters.'],
                        ]);
                    }

                    $user = User::query()->firstOrCreate(
                        ['email' => $invitation->email],
                        [
                            'name' => trim($member->first_name.' '.$member->last_name),
                            'password' => $password,
                        ],
                    );
                }

                if ($user->platform_role !== null) {
                    throw ValidationException::withMessages([
                        'token' => ['A platform administrator account cannot become a member account.'],
                    ]);
                }

                $linkedElsewhere = Member::query()
                    ->where('user_id', $user->getKey())
                    ->where('id', '<>', $member->getKey())
                    ->lockForUpdate()
                    ->first();
                if ($linkedElsewhere) {
                    throw ValidationException::withMessages([
                        'token' => ['This account is already linked to another member in this gym.'],
                    ]);
                }

                $assignment = DB::table('gym_user')
                    ->where('gym_id', $gym->getKey())
                    ->where('user_id', $user->getKey())
                    ->lockForUpdate()
                    ->first();
                if ($assignment && $assignment->role !== UserRole::Member->value) {
                    throw ValidationException::withMessages([
                        'token' => ['A staff account cannot be converted into a member account.'],
                    ]);
                }

                $gym->users()->syncWithoutDetaching([
                    $user->getKey() => [
                        'role' => UserRole::Member->value,
                        'status' => 'active',
                        'joined_at' => $assignment?->joined_at ?? now(),
                    ],
                ]);
                $gym->users()->updateExistingPivot($user->getKey(), [
                    'role' => UserRole::Member->value,
                    'status' => 'active',
                    'joined_at' => $assignment?->joined_at ?? now(),
                ]);

                $member->update(['user_id' => $user->getKey()]);
                $invitation->update([
                    'accepted_user_id' => $user->getKey(),
                    'status' => InvitationStatus::Accepted,
                    'accepted_at' => now(),
                ]);

                $this->audit->record(
                    'member.account_activated',
                    $member,
                    $user,
                    after: ['user_id' => $user->getKey(), 'invitation_id' => $invitation->getKey()],
                    request: $request,
                );

                return $user;
            });
        });
    }

    private function pendingInvitation(Gym $gym, string $plainToken): MemberAccountInvitation
    {
        $invitation = MemberAccountInvitation::query()
            ->where('token_hash', $this->tokenHash($gym->getKey(), $plainToken))
            ->first();

        if (! $this->isUsable($invitation)) {
            $this->invalidToken();
        }

        return $invitation;
    }

    private function isUsable(?MemberAccountInvitation $invitation): bool
    {
        return $invitation !== null
            && $invitation->status === InvitationStatus::Pending
            && $invitation->expires_at->isFuture();
    }

    private function tokenHash(string $gymId, string $plainToken): string
    {
        return hash('sha256', mb_strtolower($gymId).'|'.$plainToken);
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);
        $visible = mb_substr($local, 0, 1);

        return $visible.str_repeat('*', max(3, mb_strlen($local) - 1)).'@'.$domain;
    }

    private function invalidToken(): never
    {
        throw ValidationException::withMessages([
            'token' => ['The invitation is invalid or expired.'],
        ]);
    }
}
