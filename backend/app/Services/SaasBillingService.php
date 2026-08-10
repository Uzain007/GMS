<?php

namespace App\Services;

use App\Enums\PaymentProvider;
use App\Enums\SaasPlanStatus;
use App\Enums\SaasSubscriptionStatus;
use App\Enums\SubscriptionCheckoutStatus;
use App\Models\Gym;
use App\Models\GymSubscription;
use App\Models\PlatformBillingCustomer;
use App\Models\SaasPlan;
use App\Models\SaasPlanPrice;
use App\Models\SubscriptionCheckoutSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaasBillingService
{
    public function __construct(
        private readonly StripePlatformBillingService $stripe,
        private readonly AuditService $audit,
    ) {}

    public function createPlan(array $data, User $actor, Request $request): SaasPlan
    {
        $provider = $this->stripe->createProductAndPrice($data, $data);
        return DB::transaction(function () use ($data, $actor, $request, $provider): SaasPlan {
            $plan = SaasPlan::query()->create([
                'code' => mb_strtolower($data['code']),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'status' => SaasPlanStatus::Active,
                'feature_limits' => $data['feature_limits'],
                'sort_order' => $data['sort_order'] ?? 100,
                'provider' => PaymentProvider::Stripe,
                'provider_product_id' => $provider['product_id'],
            ]);
            $plan->prices()->create([
                'currency' => $data['currency'],
                'billing_interval' => $data['billing_interval'],
                'amount_minor' => $data['amount_minor'],
                'trial_days' => $data['trial_days'] ?? 0,
                'active' => true,
                'provider' => PaymentProvider::Stripe,
                'provider_price_id' => $provider['price_id'],
            ]);
            $this->audit->record('platform.saas_plan.created', $plan, $actor, after: $plan->load('prices')->toArray(), reason: 'Initial SaaS plan publication', request: $request);
            return $plan->fresh('prices');
        });
    }

    public function updatePlan(SaasPlan $plan, array $data, User $actor, Request $request): SaasPlan
    {
        $before = $plan->toArray();
        return DB::transaction(function () use ($plan, $data, $actor, $request, $before): SaasPlan {
            $plan->update(collect($data)->except('reason')->all());
            $fresh = $plan->fresh('prices');
            $this->audit->record('platform.saas_plan.updated', $fresh, $actor, $before, $fresh->toArray(), $data['reason'], $request);
            return $fresh;
        });
    }

    public function addPrice(SaasPlan $plan, array $data, User $actor, Request $request): SaasPlanPrice
    {
        $providerPriceId = $this->stripe->createPriceForPlan($plan, $data);
        return DB::transaction(function () use ($plan, $data, $actor, $request, $providerPriceId): SaasPlanPrice {
            // Prices are append-only. The previous catalogue choice becomes
            // inactive but remains attached to historical subscriptions.
            $plan->prices()->where('currency', $data['currency'])
                ->where('billing_interval', $data['billing_interval'])
                ->where('active', true)
                ->update(['active' => false]);
            $price = $plan->prices()->create([
                'currency' => $data['currency'],
                'billing_interval' => $data['billing_interval'],
                'amount_minor' => $data['amount_minor'],
                'trial_days' => $data['trial_days'] ?? 0,
                'active' => true,
                'provider' => PaymentProvider::Stripe,
                'provider_price_id' => $providerPriceId,
            ]);
            $this->audit->record('platform.saas_price.created', $price, $actor, after: $price->toArray(), reason: $data['reason'], request: $request);
            return $price;
        });
    }

    /** @return array{checkout_url: string, idempotency_reused: bool} */
    public function startCheckout(Gym $gym, SaasPlanPrice $price, string $idempotencyKey, User $actor): array
    {
        $price->loadMissing('plan');
        if (! $price->active || $price->plan->status !== SaasPlanStatus::Active) {
            throw ValidationException::withMessages(['saas_plan_price_id' => ['The selected SaaS price is not active.']]);
        }
        if ($price->currency->value !== $gym->base_currency->value) {
            throw ValidationException::withMessages(['saas_plan_price_id' => ['Select a price in the gym base currency.']]);
        }

        $current = GymSubscription::query()->whereIn('status', [
            SaasSubscriptionStatus::Incomplete->value,
            SaasSubscriptionStatus::Trialing->value,
            SaasSubscriptionStatus::Active->value,
            SaasSubscriptionStatus::PastDue->value,
            SaasSubscriptionStatus::Unpaid->value,
            SaasSubscriptionStatus::Paused->value,
        ])->latest()->first();
        if ($current) {
            throw ValidationException::withMessages(['subscription' => ['Use the billing portal to manage the existing subscription.']]);
        }

        $existing = SubscriptionCheckoutSession::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            $session = $this->stripe->retrieveCheckout($existing->provider_session_id);
            return ['checkout_url' => (string) $session['url'], 'idempotency_reused' => true];
        }

        $open = SubscriptionCheckoutSession::query()
            ->where('status', SubscriptionCheckoutStatus::Open->value)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest()->first();
        if ($open) {
            // Reuse the one active tenant checkout even when a second browser
            // generated a different request key, preventing duplicate contracts.
            $session = $this->stripe->retrieveCheckout($open->provider_session_id);
            return ['checkout_url' => (string) $session['url'], 'idempotency_reused' => true];
        }

        $customer = $this->customer($gym, $actor);
        $checkout = $this->stripe->createCheckout($gym, $customer, $price, $idempotencyKey);
        SubscriptionCheckoutSession::query()->create([
            'created_by' => $actor->getKey(),
            'saas_plan_price_id' => $price->getKey(),
            'idempotency_key' => $idempotencyKey,
            'provider_session_id' => $checkout['session_id'],
            'status' => SubscriptionCheckoutStatus::Open,
            'expires_at' => $checkout['expires_at'] ? Carbon::createFromTimestampUTC($checkout['expires_at']) : null,
        ]);
        return ['checkout_url' => $checkout['checkout_url'], 'idempotency_reused' => false];
    }

    /** @return array{portal_url: string} */
    public function createPortal(): array
    {
        $customer = PlatformBillingCustomer::query()->first();
        if (! $customer) {
            throw ValidationException::withMessages(['subscription' => ['Start a subscription before opening the billing portal.']]);
        }
        return $this->stripe->createPortal($customer);
    }

    private function customer(Gym $gym, User $actor): PlatformBillingCustomer
    {
        $existing = PlatformBillingCustomer::query()->first();
        if ($existing) {
            return $existing;
        }

        $provider = $this->stripe->createCustomer($gym, $actor->email, $gym->legal_name ?: $gym->name);
        return PlatformBillingCustomer::query()->firstOrCreate(
            ['provider' => PaymentProvider::Stripe->value],
            [
                'provider_customer_id' => (string) $provider['id'],
                'billing_email' => $actor->email,
                'billing_name' => $gym->legal_name ?: $gym->name,
                'country_code' => $gym->country_code,
                'default_currency' => $gym->base_currency,
            ],
        );
    }
}
