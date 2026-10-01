<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\GymStatus;
use App\Enums\PaymentProvider;
use App\Enums\SaasSubscriptionStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Gym;
use App\Models\GymSubscription;
use App\Models\PlatformBillingCustomer;
use App\Models\SaasBillingInvoice;
use App\Models\SaasPlan;
use App\Models\SaasPlanPrice;
use App\Models\User;
use App\Services\AutomatedSaasBillingService;
use App\Services\GymSaasStatusService;
use App\Services\StripeBillingWebhookService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SaasBillingOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_access_middleware_uses_the_current_contract_not_newer_terminal_history(): void
    {
        [$owner, $gym, $price, $customer] = $this->tenantFixture();
        app(TenantContext::class)->run($gym, function () use ($gym, $price, $customer): void {
            $this->subscription($gym, $price, $customer, SaasSubscriptionStatus::Active, [
                'billing_restricted_at' => now(),
                'provider_subscription_id' => 'manual_current_contract',
            ]);
            $this->subscription($gym, $price, $customer, SaasSubscriptionStatus::Cancelled, [
                'provider_subscription_id' => 'manual_terminal_history',
                'created_at' => now()->addMinute(),
                'updated_at' => now()->addMinute(),
            ]);
        });

        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/gyms/{$gym->id}/members", ['X-Gym-ID' => $gym->id])
            ->assertStatus(402)
            ->assertJsonPath('code', 'saas_billing_restricted');
        $this->getJson("/api/v1/gyms/{$gym->id}/saas-subscription", ['X-Gym-ID' => $gym->id])
            ->assertOk()->assertJsonPath('data.status', SaasSubscriptionStatus::Active->value);
    }

    public function test_signed_stripe_subscription_adopts_the_same_price_onboarding_contract(): void
    {
        [, $gym, $price, $manualCustomer] = $this->tenantFixture();
        $price->update(['provider_price_id' => 'price_onboarding']);
        $subscription = app(TenantContext::class)->run($gym, fn (): GymSubscription => $this->subscription(
            $gym,
            $price,
            $manualCustomer,
            SaasSubscriptionStatus::Trialing,
            ['provider_subscription_id' => GymSubscription::onboardingProviderId($gym->id)],
        ));
        $stripeCustomer = app(TenantContext::class)->run($gym, fn (): PlatformBillingCustomer => PlatformBillingCustomer::query()->create([
            'provider' => PaymentProvider::Stripe,
            'provider_customer_id' => 'cus_onboarding',
            'billing_email' => 'billing@example.test',
            'billing_name' => $gym->name,
            'country_code' => 'GB',
            'default_currency' => Currency::GBP,
        ]));

        app(StripeBillingWebhookService::class)->process([
            'id' => 'evt_onboarding_subscription',
            'type' => 'customer.subscription.created',
            'data' => ['object' => [
                'id' => 'sub_onboarding',
                'customer' => $stripeCustomer->provider_customer_id,
                'metadata' => ['gym_id' => $gym->id],
                'status' => 'trialing',
                'items' => ['data' => [['price' => ['id' => 'price_onboarding']]]],
                'current_period_start' => now()->timestamp,
                'current_period_end' => now()->addMonth()->timestamp,
                'trial_end' => now()->addDays(14)->timestamp,
            ]],
        ], '{}');

        app(TenantContext::class)->run($gym, function () use ($subscription, $stripeCustomer): void {
            $this->assertSame(1, GymSubscription::query()->count());
            $adopted = GymSubscription::query()->firstOrFail();
            $this->assertSame($subscription->id, $adopted->id);
            $this->assertSame(PaymentProvider::Stripe, $adopted->provider);
            $this->assertSame('sub_onboarding', $adopted->provider_subscription_id);
            $this->assertSame($stripeCustomer->id, $adopted->billing_customer_id);
        });
    }

    private function price(Currency $currency, string $interval, int $trialDays): SaasPlanPrice
    {
        $plan = SaasPlan::query()->create([
            'code' => 'onboarding-'.str()->lower(str()->random(8)),
            'name' => 'Onboarding Plan',
            'status' => 'active',
            'feature_limits' => ['members' => 500, 'branches' => 1, 'staff' => 8],
            'payment_methods' => ['cash', 'bank_transfer', 'stripe'],
        ]);

        return SaasPlanPrice::query()->create([
            'saas_plan_id' => $plan->id,
            'currency' => $currency,
            'billing_interval' => $interval,
            'amount_minor' => 3900,
            'trial_days' => $trialDays,
            'active' => true,
        ]);
    }

    private function tenantFixture(): array
    {
        $owner = User::factory()->create();
        $gym = Gym::factory()->create(['status' => GymStatus::Active, 'base_currency' => Currency::GBP]);
        $price = $this->price(Currency::GBP, 'monthly', 14);
        $customer = app(TenantContext::class)->run($gym, function () use ($gym, $owner): PlatformBillingCustomer {
            $gym->users()->attach($owner, ['role' => UserRole::GymOwner->value, 'status' => 'active']);
            return PlatformBillingCustomer::query()->create([
                'provider' => PaymentProvider::Manual,
                'provider_customer_id' => 'manual_test_'.$gym->id,
                'billing_email' => $owner->email,
                'billing_name' => $gym->name,
                'country_code' => 'GB',
                'default_currency' => Currency::GBP,
            ]);
        });

        return [$owner, $gym, $price, $customer];
    }

    private function subscription(
        Gym $gym,
        SaasPlanPrice $price,
        PlatformBillingCustomer $customer,
        SaasSubscriptionStatus $status,
        array $overrides = [],
    ): GymSubscription {
        return GymSubscription::query()->create(array_merge([
            'billing_customer_id' => $customer->id,
            'saas_plan_id' => $price->saas_plan_id,
            'saas_plan_price_id' => $price->id,
            'provider' => PaymentProvider::Manual,
            'provider_subscription_id' => 'manual_'.$status->value.'_'.$gym->id,
            'status' => $status,
            'plan_code_snapshot' => $price->plan->code,
            'plan_name_snapshot' => $price->plan->name,
            'feature_limits_snapshot' => $price->plan->feature_limits,
            'currency' => $price->currency,
            'amount_minor' => $price->amount_minor,
            'billing_interval' => $price->billing_interval,
            'grace_period_days' => 15,
        ], $overrides));
    }
}
