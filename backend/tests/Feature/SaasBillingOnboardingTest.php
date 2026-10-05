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
use App\Services\PlatformInsightsService;
use App\Services\StripeBillingWebhookService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SaasBillingOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_gym_onboarding_creates_one_tenant_scoped_subscription_with_snapshots(): void
    {
        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        $price = $this->price(Currency::GBP, 'monthly', 14);
        Sanctum::actingAs($admin);

        $gymId = $this->postJson('/api/v1/gyms', $this->gymPayload($price))
            ->assertCreated()
            ->assertJsonPath('data.status', GymStatus::Trial->value)
            ->json('data.id');
        $gym = Gym::query()->findOrFail($gymId);

        app(TenantContext::class)->run($gym, function () use ($gym, $price): void {
            $subscription = GymSubscription::query()->current()->firstOrFail();
            $this->assertSame($price->id, $subscription->saas_plan_price_id);
            $this->assertSame(SaasSubscriptionStatus::Trialing, $subscription->status);
            $this->assertSame(PaymentProvider::Manual, $subscription->provider);
            $this->assertTrue($subscription->isOnboardingContract());
            $this->assertSame(15, $subscription->grace_period_days);
            $this->assertSame($subscription->trial_ends_at?->timestamp, $subscription->next_billing_at?->timestamp);
            $this->assertSame('billing@example.test', $subscription->customer->billing_email);
            $this->assertSame(1, AuditLog::query()->where('event', 'saas.subscription.onboarded')->count());
            $this->assertSame($gym->id, $subscription->gym_id);
        });
    }

    public function test_repeated_gym_onboarding_request_reuses_one_complete_tenant(): void
    {
        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        $price = $this->price(Currency::GBP, 'monthly', 14);
        $payload = $this->gymPayload($price);
        Sanctum::actingAs($admin);

        $first = $this->postJson('/api/v1/gyms', $payload)
            ->assertCreated()
            ->assertJsonPath('meta.idempotency_reused', false);
        $second = $this->postJson('/api/v1/gyms', $payload)
            ->assertOk()
            ->assertJsonPath('meta.idempotency_reused', true);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Gym::query()->where('name', $payload['name'])->count());
        $gym = Gym::query()->findOrFail($first->json('data.id'));
        app(TenantContext::class)->run($gym, function (): void {
            $this->assertSame(1, PlatformBillingCustomer::query()->count());
            $this->assertSame(1, GymSubscription::query()->count());
        });
    }

    public function test_reused_onboarding_key_rejects_different_gym_details(): void
    {
        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        $price = $this->price(Currency::GBP, 'monthly', 14);
        $payload = $this->gymPayload($price);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/gyms', $payload)->assertCreated();
        $payload['name'] = 'Different Gym';
        $this->postJson('/api/v1/gyms', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
        $this->assertSame(1, Gym::query()->count());
    }

    public function test_onboarding_rejects_a_price_from_another_currency_and_rolls_back_the_gym(): void
    {
        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        $price = $this->price(Currency::USD, 'monthly', 14);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/gyms', $this->gymPayload($price))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subscription.saas_plan_price_id');

        $this->assertDatabaseMissing('gyms', ['name' => 'Subscription Onboarding Gym']);
    }

    public function test_onboarding_rejects_an_inactive_plan_and_rolls_back_the_gym(): void
    {
        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        $price = $this->price(Currency::GBP, 'monthly', 14);
        $price->plan->update(['status' => 'archived']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/gyms', $this->gymPayload($price))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subscription.saas_plan_price_id');
        $this->assertDatabaseMissing('gyms', ['name' => 'Subscription Onboarding Gym']);
    }

    public function test_onboarding_rejects_an_inactive_price_and_rolls_back_the_gym(): void
    {
        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        $price = $this->price(Currency::GBP, 'monthly', 14);
        $price->update(['active' => false]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/gyms', $this->gymPayload($price))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subscription.saas_plan_price_id');
        $this->assertDatabaseMissing('gyms', ['name' => 'Subscription Onboarding Gym']);
    }

    public function test_onboarding_requires_all_subscription_fields(): void
    {
        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        $price = $this->price(Currency::GBP, 'monthly', 14);
        $payload = $this->gymPayload($price);
        unset($payload['subscription']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/gyms', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'subscription.saas_plan_price_id',
                'subscription.billing_email',
                'subscription.grace_period_days',
            ]);
        $this->assertSame(0, Gym::query()->count());
    }

    public function test_owner_and_invite_are_rolled_back_when_subscription_creation_fails(): void
    {
        Password::shouldReceive('sendResetLink')->never();
        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        $price = $this->price(Currency::USD, 'monthly', 14);
        $payload = $this->gymPayload($price);
        $payload['owner'] = [
            'create_login_account' => true,
            'name' => 'Rolled Back Owner',
            'email' => 'rolled-back-owner@example.test',
            'phone' => '+44 7700 900099',
            'setup_method' => 'invite',
        ];
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/gyms', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subscription.saas_plan_price_id');

        $this->assertDatabaseMissing('gyms', ['name' => $payload['name']]);
        $this->assertDatabaseMissing('users', ['email' => 'rolled-back-owner@example.test']);
        $this->assertSame(0, DB::table('gym_user')->count());
    }

    public function test_zero_day_onboarding_returns_the_immediately_due_status_and_normalizes_billing_email(): void
    {
        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        $price = $this->price(Currency::GBP, 'monthly', 0);
        $payload = $this->gymPayload($price);
        $payload['subscription']['billing_email'] = '  BILLING@EXAMPLE.TEST  ';
        Sanctum::actingAs($admin);

        $gymId = $this->postJson('/api/v1/gyms', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', GymStatus::PastDue->value)
            ->json('data.id');
        $gym = Gym::query()->findOrFail($gymId);

        app(TenantContext::class)->run($gym, function (): void {
            $subscription = GymSubscription::query()->current()->firstOrFail();
            $this->assertSame(SaasSubscriptionStatus::Incomplete, $subscription->status);
            $this->assertSame('billing@example.test', $subscription->customer->billing_email);
        });
    }

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

    public function test_paused_contract_blocks_operations_keeps_billing_open_and_stops_automation(): void
    {
        [$owner, $gym, $price, $customer] = $this->tenantFixture();
        app(TenantContext::class)->run($gym, function () use ($gym, $price, $customer): void {
            $this->subscription($gym, $price, $customer, SaasSubscriptionStatus::Paused, [
                'provider_subscription_id' => 'manual_paused_contract',
                'next_billing_at' => now()->subDay(),
                'billing_override_until' => now()->addDay(),
            ]);
            app(GymSaasStatusService::class)->synchronize($gym, SaasSubscriptionStatus::Paused);
            $result = app(AutomatedSaasBillingService::class)->processTenant($gym);
            $this->assertSame(['invoices_created' => 0, 'reminders_queued' => 0, 'restricted' => 0], $result);
            $this->assertSame(0, SaasBillingInvoice::query()->count());
        });
        $this->assertSame(GymStatus::PastDue, $gym->fresh()->status);

        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/gyms/{$gym->id}/members", ['X-Gym-ID' => $gym->id])
            ->assertStatus(402);
        $this->getJson("/api/v1/gyms/{$gym->id}/saas-subscription", ['X-Gym-ID' => $gym->id])
            ->assertOk()->assertJsonPath('data.status', SaasSubscriptionStatus::Paused->value);

        app(TenantContext::class)->run($gym->fresh(), fn () => app(GymSaasStatusService::class)
            ->synchronize($gym->fresh(), SaasSubscriptionStatus::Active));
        $this->assertSame(GymStatus::Active, $gym->fresh()->status);
    }

    public function test_signed_provider_resume_clears_restriction_and_restores_operational_routes(): void
    {
        [$owner, $gym, $price, $customer] = $this->tenantFixture();
        $price->update(['provider_price_id' => 'price_paused_resume']);
        app(TenantContext::class)->run($gym, function () use ($gym, $price, $customer): void {
            $customer->update([
                'provider' => PaymentProvider::Stripe,
                'provider_customer_id' => 'cus_paused_resume',
            ]);
            $this->subscription($gym, $price, $customer, SaasSubscriptionStatus::Paused, [
                'provider' => PaymentProvider::Stripe,
                'provider_subscription_id' => 'sub_paused_resume',
                'billing_restricted_at' => now()->subDay(),
            ]);
            app(GymSaasStatusService::class)->synchronize($gym, SaasSubscriptionStatus::Paused);
        });

        app(StripeBillingWebhookService::class)->process([
            'id' => 'evt_paused_resume',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => [
                'id' => 'sub_paused_resume',
                'customer' => 'cus_paused_resume',
                'metadata' => ['gym_id' => $gym->id],
                'status' => 'active',
                'items' => ['data' => [['price' => ['id' => 'price_paused_resume']]]],
                'current_period_start' => now()->timestamp,
                'current_period_end' => now()->addMonth()->timestamp,
            ]],
        ], '{}');

        app(TenantContext::class)->run($gym->fresh(), function (): void {
            $subscription = GymSubscription::query()->current()->firstOrFail();
            $this->assertSame(SaasSubscriptionStatus::Active, $subscription->status);
            $this->assertNull($subscription->billing_restricted_at);
        });
        $this->assertSame(GymStatus::Active, $gym->fresh()->status);

        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/gyms/{$gym->id}/members", ['X-Gym-ID' => $gym->id])
            ->assertOk();
    }

    public function test_platform_analytics_uses_current_contract_instead_of_newer_terminal_history(): void
    {
        [, $gym, $price, $customer] = $this->tenantFixture();
        $active = app(TenantContext::class)->run($gym, function () use ($gym, $price, $customer): GymSubscription {
            $active = $this->subscription($gym, $price, $customer, SaasSubscriptionStatus::Active, [
                'provider_subscription_id' => 'manual_analytics_current',
            ]);
            $this->subscription($gym, $price, $customer, SaasSubscriptionStatus::Cancelled, [
                'provider_subscription_id' => 'manual_analytics_terminal',
                'created_at' => now()->addMinute(),
                'updated_at' => now()->addMinute(),
            ]);

            return $active;
        });

        $billing = app(PlatformInsightsService::class)->billing(['gym_id' => $gym->id]);
        $this->assertCount(1, $billing['subscriptions']);
        $this->assertSame($active->id, $billing['subscriptions'][0]['id']);
        $this->assertSame(SaasSubscriptionStatus::Active->value, $billing['subscriptions'][0]['status']);
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

    private function gymPayload(SaasPlanPrice $price): array
    {
        return [
            'idempotency_key' => (string) str()->uuid(),
            'name' => 'Subscription Onboarding Gym',
            'base_currency' => Currency::GBP->value,
            'country_code' => 'GB',
            'timezone' => 'Europe/London',
            'subscription' => [
                'saas_plan_price_id' => $price->id,
                'billing_email' => 'billing@example.test',
                'grace_period_days' => 15,
            ],
            'owner' => ['create_login_account' => false],
        ];
    }

    /** @return array{User,Gym,SaasPlanPrice,PlatformBillingCustomer} */
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
