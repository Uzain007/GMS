<?php

namespace App\Services;

use App\Enums\TrainerAssignmentStatus;
use App\Enums\StaffStatus;
use App\Enums\UserRole;
use App\Models\Member;
use App\Models\StaffProfile;
use App\Models\TrainerMemberAssignment;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Tenancy\TenantContext;

class TrainingAccessService
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function role(User $actor): ?UserRole
    {
        return $actor->roleForGym($this->tenant->id());
    }

    public function memberForActor(User $actor, ?string $requestedMemberId, bool $lock = false): Member
    {
        $query = Member::query();
        if ($lock) {
            $query->lockForUpdate();
        }

        if ($this->role($actor) === UserRole::Member) {
            // Self-service always derives the member from the authenticated user.
            $member = $query->where('user_id', $actor->getKey())->firstOrFail();
            if ($requestedMemberId && $requestedMemberId !== $member->getKey()) {
                abort(403, 'Members may access only their own training records.');
            }
            return $member;
        }

        abort_unless($requestedMemberId, 422, 'Select a member.');
        $member = $query->findOrFail($requestedMemberId);
        $this->assertMemberAccess($actor, $member);
        return $member;
    }

    public function trainerForActor(User $actor, ?string $requestedTrainerId): StaffProfile
    {
        if ($this->role($actor) === UserRole::Trainer) {
            $trainer = StaffProfile::query()->with('user')->where('user_id', $actor->getKey())->firstOrFail();
            $this->assertActiveTrainer($trainer);
            if ($requestedTrainerId && $requestedTrainerId !== $trainer->getKey()) {
                abort(403, 'Trainers cannot act as another trainer.');
            }
            return $trainer;
        }

        abort_unless($requestedTrainerId, 422, 'Select a trainer.');
        $trainer = StaffProfile::query()->with('user')->findOrFail($requestedTrainerId);
        $this->assertActiveTrainer($trainer);
        return $trainer;
    }

    public function assertMemberAccess(User $actor, Member $member): void
    {
        $role = $this->role($actor);
        if ($role === UserRole::Member) {
            abort_unless($member->user_id === $actor->getKey(), 403, 'Members may access only themselves.');
        } elseif ($role === UserRole::Trainer) {
            $trainer = StaffProfile::query()->with('user')->where('user_id', $actor->getKey())->firstOrFail();
            $this->assertActiveTrainer($trainer);
            abort_unless($this->hasCurrentAssignment($trainer->getKey(), $member->getKey()), 403, 'Trainer access requires an active member assignment.');
        }
    }

    public function assertPlanAccess(User $actor, WorkoutPlan $plan, bool $write = false): void
    {
        $role = $this->role($actor);
        if ($role === UserRole::Member) {
            $member = Member::query()->where('user_id', $actor->getKey())->firstOrFail();
            abort_unless(! $write && $plan->member_id === $member->getKey(), 403, 'Members cannot modify workout plans.');
        } elseif ($role === UserRole::Trainer) {
            $trainer = StaffProfile::query()->with('user')->where('user_id', $actor->getKey())->firstOrFail();
            $this->assertActiveTrainer($trainer);
            abort_unless(
                $plan->trainer_staff_profile_id === $trainer->getKey()
                && $this->hasCurrentAssignment($trainer->getKey(), $plan->member_id),
                403,
                'Trainer access requires the matching active assignment.',
            );
        }
    }

    public function hasCurrentAssignment(string $trainerId, string $memberId): bool
    {
        return TrainerMemberAssignment::query()
            ->where('trainer_staff_profile_id', $trainerId)
            ->where('member_id', $memberId)
            ->where('status', TrainerAssignmentStatus::Active->value)
            ->whereDate('starts_on', '<=', today())
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', today()))
            ->exists();
    }

    private function assertActiveTrainer(StaffProfile $trainer): void
    {
        abort_unless(
            $trainer->status === StaffStatus::Active
            && $trainer->user?->roleForGym($this->tenant->id()) === UserRole::Trainer,
            403,
            'Training access requires an active trainer profile and tenant role.',
        );
    }
}
