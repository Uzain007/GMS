<?php

namespace App\Services;

use App\Enums\BillingInterval;
use App\Enums\MembershipStatus;
use App\Enums\PlanStatus;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MembershipService
{
    public function __construct(private readonly AuditService $audit) {}

    public function create(array $data, User $actor, Request $request): Membership
    {
        return DB::transaction(function () use ($data, $actor, $request): Membership {
            // Global tenant scopes and PostgreSQL RLS independently constrain both lookups.
            $member = Member::query()->findOrFail($data['member_id']);
            $plan = MembershipPlan::query()->findOrFail($data['plan_id']);

            if ($plan->status !== PlanStatus::Active) {
                throw ValidationException::withMessages(['plan_id' => ['The selected plan is not active.']]);
            }

            if (Membership::query()
                ->where('member_id', $member->getKey())
                ->whereIn('status', [MembershipStatus::Pending->value, MembershipStatus::Active->value])
                ->exists()) {
                throw ValidationException::withMessages(['member_id' => ['The member already has a current membership.']]);
            }

            $branchId = $data['branch_id'] ?? $plan->branch_id ?? $member->home_branch_id;
            if ($plan->branch_id && $branchId !== $plan->branch_id) {
                throw ValidationException::withMessages(['branch_id' => ['This plan is restricted to its assigned branch.']]);
            }

            $startsAt = CarbonImmutable::parse($data['starts_at']);
            $endsAt = isset($data['ends_at'])
                ? CarbonImmutable::parse($data['ends_at'])
                : ($plan->duration_days ? $startsAt->addDays($plan->duration_days) : null);

            $membership = Membership::query()->create([
                'member_id' => $member->getKey(),
                'plan_id' => $plan->getKey(),
                'branch_id' => $branchId,
                'created_by' => $actor->getKey(),
                'status' => $data['status'] ?? MembershipStatus::Active->value,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'next_billing_at' => $this->nextBillingAt($plan, $startsAt),
                // Copy all price and contract fields so plan edits never rewrite history.
                'price_amount_minor' => $plan->price_amount_minor,
                'currency' => $plan->currency,
                'joining_fee_minor' => $plan->joining_fee_minor,
                'billing_interval' => $plan->billing_interval,
                'interval_count' => $plan->interval_count,
                'auto_renew' => $data['auto_renew'] ?? true,
                'terms_snapshot' => $plan->terms,
            ]);

            $this->audit->record(
                'membership.created',
                $membership,
                $actor,
                after: $membership->toArray(),
                request: $request,
            );

            return $membership;
        });
    }

    public function update(Membership $membership, array $data, User $actor, Request $request): Membership
    {
        return DB::transaction(function () use ($membership, $data, $actor, $request): Membership {
            $locked = Membership::query()->lockForUpdate()->findOrFail($membership->getKey());
            $before = $locked->toArray();
            $reason = (string) $data['reason'];
            unset($data['reason']);

            if (isset($data['plan_id']) && $data['plan_id'] !== $locked->plan_id) {
                // The replacement plan is tenant-scoped twice: the model concern
                // and PostgreSQL RLS. Its accepted terms become the amended
                // contract snapshot while the audit row preserves the original.
                $plan = MembershipPlan::query()->findOrFail($data['plan_id']);
                if ($plan->status !== PlanStatus::Active) {
                    throw ValidationException::withMessages(['plan_id' => ['The selected plan is not active.']]);
                }
                if ($plan->branch_id && $locked->branch_id && $locked->branch_id !== $plan->branch_id) {
                    throw ValidationException::withMessages(['plan_id' => ['The selected plan is not valid for this membership branch.']]);
                }
                $data = array_merge($data, [
                    'branch_id' => $plan->branch_id ?? $locked->branch_id,
                    'price_amount_minor' => $plan->price_amount_minor,
                    'currency' => $plan->currency,
                    'joining_fee_minor' => $plan->joining_fee_minor,
                    'billing_interval' => $plan->billing_interval,
                    'interval_count' => $plan->interval_count,
                    'terms_snapshot' => $plan->terms,
                ]);
            }

            $endsAt = array_key_exists('ends_at', $data) && $data['ends_at']
                ? CarbonImmutable::parse($data['ends_at']) : $locked->ends_at;
            $nextBilling = array_key_exists('next_billing_at', $data) && $data['next_billing_at']
                ? CarbonImmutable::parse($data['next_billing_at']) : null;
            if ($endsAt && $endsAt->lt($locked->starts_at)) {
                throw ValidationException::withMessages(['ends_at' => ['The expiry date cannot be before the membership start date.']]);
            }
            if ($nextBilling && $nextBilling->lt($locked->starts_at)) {
                throw ValidationException::withMessages(['next_billing_at' => ['The next billing date cannot be before the membership start date.']]);
            }

            if (($data['status'] ?? null) === MembershipStatus::Cancelled->value) {
                $data['cancelled_at'] = now();
                $data['auto_renew'] = false;
            }
            $locked->update($data);
            $fresh = $locked->fresh();
            $this->audit->record('membership.updated', $fresh, $actor, $before, $fresh->toArray(), $reason, $request);
            return $fresh;
        });
    }

    private function nextBillingAt(MembershipPlan $plan, CarbonImmutable $startsAt): ?CarbonImmutable
    {
        if ($plan->billing_interval === BillingInterval::OneTime) {
            return null;
        }

        if ($plan->trial_days > 0) {
            return $startsAt->addDays($plan->trial_days);
        }

        return match ($plan->billing_interval) {
            BillingInterval::Weekly => $startsAt->addWeeks($plan->interval_count),
            BillingInterval::Monthly => $startsAt->addMonths($plan->interval_count),
            BillingInterval::Quarterly => $startsAt->addMonths(3 * $plan->interval_count),
            BillingInterval::Yearly => $startsAt->addYears($plan->interval_count),
            BillingInterval::OneTime => null,
        };
    }
}
