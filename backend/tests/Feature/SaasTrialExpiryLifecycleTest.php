<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\GymStatus;
use App\Enums\PaymentProvider;
use App\Enums\SaasSubscriptionStatus;
use App\Enums\UserRole;
use App\Jobs\SendSaasBillingReminder;
use App\Models\Gym;
use App\Models\GymSubscription;
use App\Models\PlatformBillingCustomer;
use App\Models\SaasBillingNotification;
use App\Models\SaasPlan;
use App\Models\SaasPlanPrice;
use App\Models\User;
use App\Services\AutomatedSaasBillingService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SaasTrialExpiryLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_one_day_trial_notice_is_queued_emailed_and_idempotent(): void
    {
        Carbon::setTestNow('2026-10-01 09:00:00 UTC');
        Queue::fake();
        [$owner, $gym, , $subscription] = $this->trialTenant(now()->addDay());

        app(TenantContext::class)->run($gym, function () use ($gym): void {
            app(AutomatedSaasBillingService::class)->processTenant($gym);
            app(AutomatedSaasBillingService::class)->processTenant($gym);
        });

        $notification = app(TenantContext::class)->run($gym, function () use ($subscription): SaasBillingNotification {
            $this->assertSame(1, SaasBillingNotification::query()->where('template_key', 'saas_trial_ending')->count());
            return SaasBillingNotification::query()
                ->where('gym_subscription_id', $subscription->id)
                ->where('template_key', 'saas_trial_ending')
                ->firstOrFail();
        });
        $this->assertSame($owner->id, $notification->recipient_user_id);
        Queue::assertPushed(SendSaasBillingReminder::class, 2);

        config(['mail.default' => 'array']);
        (new SendSaasBillingReminder($gym->id, $notification->id))->handle(app(TenantContext::class));
        $this->assertSame('sent', $notification->fresh()->status);
        $this->assertNotNull($notification->fresh()->sent_at);
    }

    public function test_expired_trial_restricts_operations_but_manager_can_pay_and_restore_access(): void
    {
        Carbon::setTestNow('2026-10-02 10:00:00 UTC');
        Queue::fake();
        [$owner, $gym, $price, $subscription] = $this->trialTenant(now()->subMinute());
        $manager = User::factory()->create();
        app(TenantContext::class)->run($gym, fn () => $gym->users()->attach($manager, [
            'role' => UserRole::GymManager->value,
            'status' => 'active',
        ]));

        app(TenantContext::class)->run($gym, function () use ($gym): void {
            app(AutomatedSaasBillingService::class)->processTenant($gym);
            app(AutomatedSaasBillingService::class)->processTenant($gym);
            $this->assertSame(1, SaasBillingNotification::query()
                ->where('template_key', 'saas_trial_expired')->count());
        });
        $this->assertSame(SaasSubscriptionStatus::PastDue, $subscription->fresh()->status);
        $this->assertNotNull($subscription->fresh()->billing_restricted_at);
        $this->assertSame(GymStatus::PastDue, $gym->fresh()->status);
        Queue::assertPushed(SendSaasBillingReminder::class, 1);

        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/gyms/{$gym->id}/members", ['X-Gym-ID' => $gym->id])
            ->assertStatus(402)
            ->assertJsonPath('code', 'saas_billing_restricted')
            ->assertJsonPath('reason', 'trial_expired');
        $this->getJson("/api/v1/gyms/{$gym->id}/saas-subscription", ['X-Gym-ID' => $gym->id])
            ->assertOk()
            ->assertJsonPath('data.status', 'past_due');

        Sanctum::actingAs($manager);
        $invoice = $this->postJson("/api/v1/gyms/{$gym->id}/saas-subscription/manual-invoice", [
            'saas_plan_price_id' => $price->id,
            'method' => 'cash',
            'idempotency_key' => 'expired-trial-manager-invoice-0001',
        ], ['X-Gym-ID' => $gym->id])->assertCreated()->json('data');
        $payment = $this->postJson("/api/v1/gyms/{$gym->id}/saas-subscription/manual-payments", [
            'saas_billing_invoice_id' => $invoice['id'],
            'method' => 'cash',
            'payment_date' => today()->toDateString(),
            'idempotency_key' => 'expired-trial-manager-payment-0001',
        ], ['X-Gym-ID' => $gym->id])->assertCreated()->json('data');

        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        Sanctum::actingAs($admin);
        $this->patchJson(
            "/api/v1/gyms/{$gym->id}/saas-subscription/manual-payments/{$payment['id']}/review",
            ['decision' => 'approve', 'reason' => 'Expired trial cash payment verified.'],
            ['X-Gym-ID' => $gym->id],
        )->assertOk()->assertJsonPath('data.status', 'paid');

        app(TenantContext::class)->run($gym, function (): void {
            $this->assertSame(1, GymSubscription::query()->count());
            $restored = GymSubscription::query()->firstOrFail();
            $this->assertSame(SaasSubscriptionStatus::Active, $restored->status);
            $this->assertNull($restored->billing_restricted_at);
            $this->assertSame(1, SaasBillingNotification::query()->where('template_key', 'saas_invoice_created')->count());
            $this->assertSame(1, SaasBillingNotification::query()->where('template_key', 'saas_invoice_paid')->count());
            $this->assertSame(1, SaasBillingNotification::query()->where('template_key', 'saas_account_restored')->count());
        });
        $this->assertSame(GymStatus::Active, $gym->fresh()->status);

        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/gyms/{$gym->id}/members", ['X-Gym-ID' => $gym->id])
            ->assertOk();
    }

    public function test_active_paid_subscription_suppresses_stale_trial_notifications_and_restriction(): void
    {
        Carbon::setTestNow('2026-10-02 10:00:00 UTC');
        Queue::fake();
        [, $gym, , $subscription] = $this->trialTenant(now()->subDay(), SaasSubscriptionStatus::Active);
        $gym->update(['status' => GymStatus::Active]);

        $result = app(TenantContext::class)->run(
            $gym,
            fn (): array => app(AutomatedSaasBillingService::class)->processTenant($gym),
        );

        $this->assertSame(0, $result['restricted']);
        $this->assertSame(SaasSubscriptionStatus::Active, $subscription->fresh()->status);
        $this->assertNull($subscription->fresh()->billing_restricted_at);
        app(TenantContext::class)->run($gym, fn () => $this->assertSame(
            0,
            SaasBillingNotification::query()->whereIn('template_key', ['saas_trial_ending', 'saas_trial_expired'])->count(),
        ));
    }

    public function test_legacy_trial_fallback_is_tenant_isolated_and_restricts_without_a_subscription(): void
    {
        Carbon::setTestNow('2026-10-02 10:00:00 UTC');
        Queue::fake();
        $owner = User::factory()->create();
        $gym = Gym::factory()->create([
            'status' => GymStatus::Trial,
            'timezone' => 'UTC',
            'trial_ends_at' => now()->subMinute(),
        ]);
        $otherGym = Gym::factory()->create(['status' => GymStatus::Trial, 'timezone' => 'UTC']);
        app(TenantContext::class)->run($gym, fn () => $gym->users()->attach($owner, [
            'role' => UserRole::GymOwner->value,
            'status' => 'active',
        ]));

        app(TenantContext::class)->run($gym, function () use ($gym): void {
            app(AutomatedSaasBillingService::class)->processTenant($gym);
            $notice = SaasBillingNotification::query()->where('template_key', 'saas_trial_expired')->firstOrFail();
            $this->assertNull($notice->gym_subscription_id);
            $this->assertNull($notice->saas_billing_invoice_id);
        });
        app(TenantContext::class)->run($otherGym, fn () => $this->assertSame(0, SaasBillingNotification::query()->count()));
        $this->assertSame(GymStatus::PastDue, $gym->fresh()->status);

        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/gyms/{$gym->id}/members", ['X-Gym-ID' => $gym->id])
            ->assertStatus(402)
            ->assertJsonPath('reason', 'trial_expired');
        $this->getJson("/api/v1/gyms/{$gym->id}/saas-plans", ['X-Gym-ID' => $gym->id])
            ->assertOk();
    }

    /** @return array{User,Gym,SaasPlanPrice,GymSubscription} */
    private function trialTenant(Carbon $trialEndsAt, SaasSubscriptionStatus $status = SaasSubscriptionStatus::Trialing): array
    {
        $owner = User::factory()->create();
        $gym = Gym::factory()->create([
            'status' => $status === SaasSubscriptionStatus::Active ? GymStatus::Active : GymStatus::Trial,
            'base_currency' => Currency::GBP,
            'timezone' => 'UTC',
            'trial_ends_at' => $trialEndsAt,
        ]);
        $plan = SaasPlan::query()->create([
            'code' => 'trial-lifecycle-'.str()->lower(str()->random(8)),
            'name' => 'Trial Lifecycle',
            'status' => 'active',
            'feature_limits' => ['members' => 100, 'branches' => 1, 'staff' => 5],
            'payment_methods' => ['cash', 'stripe'],
        ]);
        $price = SaasPlanPrice::query()->create([
            'saas_plan_id' => $plan->id,
            'currency' => Currency::GBP,
            'billing_interval' => 'monthly',
            'amount_minor' => 4900,
            'trial_days' => 14,
            'active' => true,
        ]);
        $subscription = app(TenantContext::class)->run($gym, function () use ($gym, $owner, $plan, $price, $trialEndsAt, $status): GymSubscription {
            $gym->users()->attach($owner, ['role' => UserRole::GymOwner->value, 'status' => 'active']);
            $customer = PlatformBillingCustomer::query()->create([
                'provider' => PaymentProvider::Manual,
                'provider_customer_id' => 'manual_trial_'.$gym->id,
                'billing_email' => $owner->email,
                'billing_name' => $gym->name,
                'country_code' => 'GB',
                'default_currency' => Currency::GBP,
            ]);
            return GymSubscription::query()->create([
                'billing_customer_id' => $customer->id,
                'saas_plan_id' => $plan->id,
                'saas_plan_price_id' => $price->id,
                'provider' => PaymentProvider::Manual,
                'provider_subscription_id' => GymSubscription::onboardingProviderId($gym->id),
                'status' => $status,
                'plan_code_snapshot' => $plan->code,
                'plan_name_snapshot' => $plan->name,
                'feature_limits_snapshot' => $plan->feature_limits,
                'currency' => Currency::GBP,
                'amount_minor' => $price->amount_minor,
                'billing_interval' => 'monthly',
                'current_period_start' => $status === SaasSubscriptionStatus::Active ? now()->subDay() : null,
                'current_period_end' => $status === SaasSubscriptionStatus::Active ? now()->addMonth() : null,
                'trial_ends_at' => $trialEndsAt,
                'next_billing_at' => $status === SaasSubscriptionStatus::Active ? now()->addMonth() : $trialEndsAt,
                'grace_period_days' => 15,
            ]);
        });

        return [$owner, $gym, $price, $subscription];
    }
}
