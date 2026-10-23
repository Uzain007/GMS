<?php

namespace App\Services;

use App\Enums\SaasPlanChangeRequestStatus;
use App\Enums\SaasPlanStatus;
use App\Models\Gym;
use App\Models\GymSubscription;
use App\Models\SaasPlanChangeRequest;
use App\Models\SaasPlanPrice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaasPlanChangeRequestService
{
    public function __construct(private readonly AuditService $audit, private readonly BillingNotificationService $notifications) {}

    public function create(Gym $gym, SaasPlanPrice $requestedPrice, User $actor, string $reason, Request $request): SaasPlanChangeRequest
    {
        $requestedPrice->loadMissing('plan');
        if (! $requestedPrice->active || $requestedPrice->plan->status !== SaasPlanStatus::Active
            || $requestedPrice->currency->value !== $gym->base_currency->value) {
            throw ValidationException::withMessages(['saas_plan_price_id' => ['Select an active plan price in the gym base currency.']]);
        }
        $change = DB::transaction(function () use ($requestedPrice, $actor, $reason, $request): SaasPlanChangeRequest {
            $subscription = GymSubscription::query()->current()->with(['plan', 'price'])->lockForUpdate()->latest()->first();
            if (! $subscription || ! $subscription->price) {
                throw ValidationException::withMessages(['subscription' => ['An existing subscription is required before requesting a plan change.']]);
            }
            if ($subscription->saas_plan_price_id === $requestedPrice->id) {
                throw ValidationException::withMessages(['saas_plan_price_id' => ['This is already the current subscription price.']]);
            }
            if (SaasPlanChangeRequest::query()->where('status', SaasPlanChangeRequestStatus::Pending->value)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['request' => ['This gym already has a pending plan change request.']]);
            }
            $changeType = $requestedPrice->amount_minor > $subscription->amount_minor ? 'upgrade'
                : ($requestedPrice->amount_minor < $subscription->amount_minor ? 'downgrade' : 'change');
            $change = SaasPlanChangeRequest::query()->create([
                'gym_subscription_id' => $subscription->id,
                'current_saas_plan_id' => $subscription->saas_plan_id,
                'current_saas_plan_price_id' => $subscription->saas_plan_price_id,
                'requested_saas_plan_id' => $requestedPrice->saas_plan_id,
                'requested_saas_plan_price_id' => $requestedPrice->id,
                'requested_by' => $actor->id, 'change_type' => $changeType,
                'status' => SaasPlanChangeRequestStatus::Pending,
                'reason' => $reason, 'requested_at' => now(),
            ]);
            $this->audit->record('saas.plan_change.requested', $change, $actor,
                before: ['plan' => $subscription->plan_name_snapshot, 'amount_minor' => $subscription->amount_minor, 'currency' => $subscription->currency->value],
                after: ['plan' => $requestedPrice->plan->name, 'amount_minor' => $requestedPrice->amount_minor, 'currency' => $requestedPrice->currency->value, 'change_type' => $changeType],
                reason: $reason, request: $request);
            return $change;
        });
        $this->notifications->saasPlanChangeRequested($gym, $change);
        return $this->load($change);
    }

    public function cancel(SaasPlanChangeRequest $change, User $actor, Request $request): SaasPlanChangeRequest
    {
        return DB::transaction(function () use ($change, $actor, $request): SaasPlanChangeRequest {
            $locked = SaasPlanChangeRequest::query()->lockForUpdate()->findOrFail($change->id);
            if ($locked->status !== SaasPlanChangeRequestStatus::Pending || $locked->requested_by !== $actor->id) {
                throw ValidationException::withMessages(['request' => ['Only the requester can cancel a pending plan change.']]);
            }
            $locked->update(['status' => SaasPlanChangeRequestStatus::Cancelled, 'cancelled_at' => now()]);
            $this->audit->record('saas.plan_change.cancelled', $locked, $actor, reason: 'The gym cancelled its pending plan change.', request: $request);
            return $this->load($locked);
        });
    }

    public function review(Gym $gym, string $id, string $decision, string $reason, User $actor, Request $request): SaasPlanChangeRequest
    {
        $change = DB::transaction(function () use ($id, $decision, $reason, $actor, $request): SaasPlanChangeRequest {
            $locked = SaasPlanChangeRequest::query()->with(['subscription', 'requestedPlan', 'requestedPrice.plan'])->lockForUpdate()->findOrFail($id);
            if ($locked->status !== SaasPlanChangeRequestStatus::Pending) {
                throw ValidationException::withMessages(['request' => ['This plan change request is no longer pending.']]);
            }
            $status = $decision === 'approve' ? SaasPlanChangeRequestStatus::Approved : SaasPlanChangeRequestStatus::Rejected;
            if ($status === SaasPlanChangeRequestStatus::Approved) {
                $price = $locked->requestedPrice;
                if (! $price->active || $price->plan->status !== SaasPlanStatus::Active) {
                    throw ValidationException::withMessages(['request' => ['The requested price is no longer selectable.']]);
                }
                $subscription = GymSubscription::query()->lockForUpdate()->findOrFail($locked->gym_subscription_id);
                $before = $subscription->only(['saas_plan_id', 'saas_plan_price_id', 'plan_name_snapshot', 'amount_minor', 'currency', 'billing_interval']);
                // Accepted invoices remain immutable; the approved snapshot controls every future billing cycle.
                $subscription->update([
                    'saas_plan_id' => $price->saas_plan_id, 'saas_plan_price_id' => $price->id,
                    'plan_code_snapshot' => $price->plan->code, 'plan_name_snapshot' => $price->plan->name,
                    'feature_limits_snapshot' => $price->plan->feature_limits,
                    'currency' => $price->currency, 'amount_minor' => $price->amount_minor,
                    'billing_interval' => $price->billing_interval,
                ]);
                $this->audit->record('saas.plan_change.approved', $locked, $actor, $before,
                    $subscription->fresh()->only(array_keys($before)), $reason, $request);
            } else {
                $this->audit->record('saas.plan_change.rejected', $locked, $actor, reason: $reason, request: $request);
            }
            $locked->update(['status' => $status, 'reviewed_by' => $actor->id, 'review_reason' => $reason, 'reviewed_at' => now()]);
            return $this->load($locked);
        });
        $this->notifications->saasPlanChangeReviewed($gym, $change);
        return $change;
    }

    public function load(SaasPlanChangeRequest $change): SaasPlanChangeRequest
    {
        return $change->fresh()->load(['currentPlan', 'currentPrice', 'requestedPlan', 'requestedPrice', 'requestedBy:id,name,email', 'reviewedBy:id,name']);
    }
}
