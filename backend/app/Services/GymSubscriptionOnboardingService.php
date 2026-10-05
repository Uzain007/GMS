<?php

namespace App\Services;

use App\Enums\PaymentProvider;
use App\Enums\SaasPlanStatus;
use App\Enums\SaasSubscriptionStatus;
use App\Models\Gym;
use App\Models\GymSubscription;
use App\Models\PlatformBillingCustomer;
use App\Models\SaasPlanPrice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class GymSubscriptionOnboardingService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly GymSaasStatusService $gymStatus,
    ) {}

    public function create(
        Gym $gym,
        SaasPlanPrice $price,
        string $billingEmail,
        int $gracePeriodDays,
        User $actor,
        Request $request,
    ): GymSubscription {
        $price->loadMissing('plan');
        if (! $price->active || $price->plan->status !== SaasPlanStatus::Active) {
            throw ValidationException::withMessages([
                'subscription.saas_plan_price_id' => ['Select an active published SaaS plan price.'],
            ]);
        }
        if ($price->currency->value !== $gym->base_currency->value) {
            throw ValidationException::withMessages([
                'subscription.saas_plan_price_id' => ['Select a SaaS price in the gym base currency.'],
            ]);
        }

        $customer = PlatformBillingCustomer::query()->create([
            'provider' => PaymentProvider::Manual,
            'provider_customer_id' => 'manual_onboarding_'.$gym->getKey(),
            'billing_email' => $billingEmail,
            'billing_name' => $gym->legal_name ?: $gym->name,
            'country_code' => $gym->country_code,
            'default_currency' => $gym->base_currency,
        ]);
        $periodStart = now();
        $trialEndsAt = $price->trial_days > 0 ? $periodStart->copy()->addDays($price->trial_days) : null;
        $status = $trialEndsAt ? SaasSubscriptionStatus::Trialing : SaasSubscriptionStatus::Incomplete;

        $subscription = GymSubscription::query()->create([
            'billing_customer_id' => $customer->getKey(),
            'saas_plan_id' => $price->plan->getKey(),
            'saas_plan_price_id' => $price->getKey(),
            'provider' => PaymentProvider::Manual,
            'provider_subscription_id' => GymSubscription::onboardingProviderId((string) $gym->getKey()),
            'status' => $status,
            'plan_code_snapshot' => $price->plan->code,
            'plan_name_snapshot' => $price->plan->name,
            'feature_limits_snapshot' => $price->plan->feature_limits,
            'currency' => $price->currency,
            'amount_minor' => $price->amount_minor,
            'billing_interval' => $price->billing_interval,
            'current_period_start' => $periodStart,
            'current_period_end' => $trialEndsAt,
            'next_billing_at' => $trialEndsAt ?? $periodStart,
            'trial_ends_at' => $trialEndsAt,
            'grace_period_days' => $gracePeriodDays,
        ]);

        $this->gymStatus->synchronize(
            $gym,
            $status,
            $trialEndsAt,
            $actor,
            'SaaS subscription selected during gym onboarding.',
            $request,
        );
        $this->audit->record('saas.subscription.onboarded', $subscription, $actor, after: [
            'saas_plan_id' => $price->plan->getKey(),
            'saas_plan_price_id' => $price->getKey(),
            'status' => $status->value,
            'billing_interval' => $price->billing_interval,
            'currency' => $price->currency->value,
            'amount_minor' => $price->amount_minor,
            'next_billing_at' => $subscription->next_billing_at?->toIso8601String(),
            'grace_period_days' => $gracePeriodDays,
        ], reason: 'Initial SaaS contract created with the tenant.', request: $request);

        return $subscription->fresh(['customer', 'plan', 'price']);
    }
}
