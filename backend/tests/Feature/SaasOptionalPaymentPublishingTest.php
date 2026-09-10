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
use App\Models\Payment;
use App\Models\PlatformBillingCustomer;
use App\Models\SaasBillingInvoice;
use App\Models\SaasPlan;
use App\Models\SaasPlanPrice;
use App\Models\SaasSubscriptionPayment;
use App\Models\SaasBillingNotification;
use App\Models\Member;
use App\Models\User;
use App\Services\AutomatedSaasBillingService;
use App\Services\StripeBillingWebhookService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SaasOptionalPaymentPublishingTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_publish_and_list_a_saas_plan_without_stripe_configuration(): void
    {
        $this->disableStripe();
        Http::preventStrayRequests();
        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/platform/saas-plans', $this->planPayload('local-flex'))
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.payment_methods.0', 'bank_transfer')
            ->assertJsonPath('data.payment_methods.1', 'cash');

        $planId = $response->json('data.id');
        $priceId = $response->json('data.prices.0.id');
        $this->assertDatabaseHas('saas_plans', [
            'id' => $planId,
            'code' => 'local-flex',
            'status' => 'active',
            'provider_product_id' => null,
        ]);
        $this->assertDatabaseHas('saas_plan_prices', [
            'id' => $priceId,
            'saas_plan_id' => $planId,
            'provider_price_id' => null,
            'active' => true,
        ]);
        $this->getJson('/api/v1/platform/saas-plans?per_page=100')
            ->assertSuccessful()
            ->assertJsonFragment(['id' => $planId, 'code' => 'local-flex']);
        $this->withDatabaseIdentity($admin, function () use ($admin): void {
            $this->assertDatabaseHas('audit_logs', [
                'gym_id' => null,
                'actor_id' => $admin->id,
                'event' => 'platform.saas_plan.created',
            ]);
        });

        $ordinaryUser = User::factory()->create();
        Sanctum::actingAs($ordinaryUser);
        $this->postJson('/api/v1/platform/saas-plans', $this->planPayload('unauthorised-plan'))
            ->assertForbidden();
    }

    public function test_bank_transfer_is_tenant_safe_and_activates_only_after_platform_review(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        [$owner, $gym] = $this->tenant(UserRole::GymOwner);
        $trialEndsAt = now()->addDays(14)->startOfSecond();
        $gym->update(['status' => GymStatus::Trial, 'trial_ends_at' => $trialEndsAt]);
        [, $otherGym] = $this->tenant(UserRole::GymOwner);
        [$plan, $price] = $this->catalogue(['bank_transfer']);
        Sanctum::actingAs($owner);

        $this->post(
            "/api/v1/gyms/{$gym->id}/saas-subscription/manual-payments",
            [
                'saas_plan_price_id' => $price->id,
                'method' => 'bank_transfer',
                'idempotency_key' => 'saas-bank-transfer-no-date',
                'reference' => 'BANK-NO-DATE',
                'receipt' => UploadedFile::fake()->create('subscription-receipt.pdf', 10, 'application/pdf'),
            ],
            ['Accept' => 'application/json', 'X-Gym-ID' => $gym->id],
        )->assertUnprocessable()->assertJsonValidationErrors('payment_date');

        $created = $this->post(
            "/api/v1/gyms/{$gym->id}/saas-subscription/manual-payments",
            [
                'saas_plan_price_id' => $price->id,
                'method' => 'bank_transfer',
                'payment_date' => today()->toDateString(),
                'idempotency_key' => 'saas-bank-transfer-0001',
                'reference' => 'BANK-LOCAL-001',
                'receipt' => UploadedFile::fake()->create('subscription-receipt.pdf', 10, 'application/pdf'),
            ],
            ['Accept' => 'application/json', 'X-Gym-ID' => $gym->id],
        )->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.method', 'bank_transfer')
            ->assertJsonPath('data.has_receipt', true)
            ->assertJsonMissingPath('data.receipt_path');
        $paymentId = $created->json('data.id');
        $this->assertSame(GymStatus::Trial, $gym->fresh()->status);
        $this->assertSame($trialEndsAt->timestamp, $gym->fresh()->trial_ends_at?->timestamp);

        $this->patchJson(
            "/api/v1/gyms/{$gym->id}/saas-subscription/manual-payments/{$paymentId}/review",
            ['decision' => 'approve', 'reason' => 'Owner cannot self-approve.'],
            ['X-Gym-ID' => $gym->id],
        )->assertForbidden();

        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        Sanctum::actingAs($admin);
        $this->getJson(
            "/api/v1/gyms/{$otherGym->id}/saas-subscription/manual-payments/{$paymentId}/receipt",
            ['X-Gym-ID' => $otherGym->id],
        )->assertNotFound();
        $this->getJson(
            "/api/v1/gyms/{$otherGym->id}/saas-subscription/manual-payments",
            ['X-Gym-ID' => $otherGym->id],
        )->assertSuccessful()->assertJsonMissing(['id' => $paymentId]);
        $this->patchJson(
            "/api/v1/gyms/{$gym->id}/saas-subscription/manual-payments/{$paymentId}/review",
            ['decision' => 'approve', 'reason' => 'Bank receipt verified against the platform account.'],
            ['X-Gym-ID' => $gym->id],
        )->assertSuccessful()
            ->assertJsonPath('data.status', 'paid');

        app(TenantContext::class)->run($gym, function () use ($paymentId, $plan, $price): void {
            $payment = SaasSubscriptionPayment::query()->findOrFail($paymentId);
            $subscription = GymSubscription::query()->findOrFail($payment->gym_subscription_id);
            $invoice = SaasBillingInvoice::query()->findOrFail($payment->saas_billing_invoice_id);
            $this->assertSame('manual', $subscription->provider->value);
            $this->assertSame('active', $subscription->status->value);
            $this->assertSame($plan->id, $subscription->saas_plan_id);
            $this->assertSame($price->id, $subscription->saas_plan_price_id);
            $this->assertSame('paid', $invoice->status->value);
            $this->assertSame(7900, $invoice->amount_paid_minor);
            $this->assertNotNull($subscription->current_period_start);
            $this->assertSame(
                $subscription->current_period_start->copy()->addMonthNoOverflow()->timestamp,
                $subscription->current_period_end?->timestamp,
            );
            $this->assertSame($subscription->current_period_end?->timestamp, $invoice->period_end?->timestamp);
        });
        $this->assertSame(GymStatus::Active, $gym->fresh()->status);
        $this->assertNull($gym->fresh()->trial_ends_at);
        app(TenantContext::class)->run($gym, function (): void {
            // The SaaS ledger and gym-member payment ledger stay separate, and
            // their tenant audit evidence remains behind forced RLS.
            $this->assertSame(0, Payment::query()->withoutGlobalScopes()->count());
            $this->assertTrue(AuditLog::query()->where('event', 'saas.subscription_payment.approved')->exists());
            $this->assertTrue(AuditLog::query()->where('event', 'gym.saas_status.synchronized')->exists());
        });
    }

    public function test_unpaid_trial_stays_trial_until_a_payment_is_approved(): void
    {
        [$owner, $gym] = $this->tenant(UserRole::GymOwner);
        $trialEndsAt = now()->addDays(14)->startOfSecond();
        $gym->update(['status' => GymStatus::Trial, 'trial_ends_at' => $trialEndsAt]);
        [, $price] = $this->catalogue(['cash']);
        Sanctum::actingAs($owner);

        $payment = $this->postJson(
            "/api/v1/gyms/{$gym->id}/saas-subscription/manual-payments",
            [
                'saas_plan_price_id' => $price->id,
                'method' => 'cash',
                'idempotency_key' => 'saas-trial-unpaid-0001',
                'reference' => 'AWAITING-CASH-001',
            ],
            ['X-Gym-ID' => $gym->id],
        )->assertCreated()->assertJsonPath('data.status', 'pending');

        $this->assertSame(GymStatus::Trial, $gym->fresh()->status);
        $this->assertSame($trialEndsAt->timestamp, $gym->fresh()->trial_ends_at?->timestamp);
        app(TenantContext::class)->run($gym, function () use ($payment): void {
            $this->assertNull(SaasSubscriptionPayment::query()->findOrFail($payment->json('data.id'))->gym_subscription_id);
            $this->assertFalse(AuditLog::query()->where('event', 'gym.saas_status.synchronized')->exists());
        });
    }

    public function test_failed_stripe_invoice_moves_active_gym_to_past_due(): void
    {
        [, $gym] = $this->tenant(UserRole::GymOwner);
        [$plan, $price] = $this->catalogue(['stripe']);
        [$customer, $subscription] = $this->stripeSubscription($gym, $plan, $price);
        $periodStart = now()->subMonth()->startOfSecond();
        $periodEnd = now()->startOfSecond();

        app(StripeBillingWebhookService::class)->process([
            'id' => 'evt_invoice_failed_status_001',
            'type' => 'invoice.payment_failed',
            'data' => ['object' => [
                'id' => 'in_failed_status_001',
                'customer' => $customer->provider_customer_id,
                'subscription' => $subscription->provider_subscription_id,
                'status' => 'open',
                'currency' => 'gbp',
                'amount_due' => 7900,
                'amount_paid' => 0,
                'amount_remaining' => 7900,
                'period_start' => $periodStart->timestamp,
                'period_end' => $periodEnd->timestamp,
            ]],
        ], '{"event":"failed-status"}');

        $this->assertSame(GymStatus::PastDue, $gym->fresh()->status);
        app(TenantContext::class)->run($gym, function () use ($subscription): void {
            $this->assertSame(SaasSubscriptionStatus::PastDue, $subscription->fresh()->status);
            $this->assertTrue(AuditLog::query()->where('event', 'gym.saas_status.synchronized')->exists());
        });
    }

    public function test_paid_stripe_invoice_ends_trial_and_sets_the_renewal_period(): void
    {
        [, $gym] = $this->tenant(UserRole::GymOwner);
        $trialEndsAt = now()->addDays(7)->startOfSecond();
        $gym->update(['status' => GymStatus::Trial, 'trial_ends_at' => $trialEndsAt]);
        [$plan, $price] = $this->catalogue(['stripe']);
        [$customer, $subscription] = $this->stripeSubscription($gym, $plan, $price);
        app(TenantContext::class)->run($gym, fn () => $subscription->update([
            'status' => SaasSubscriptionStatus::Trialing,
            'trial_ends_at' => $trialEndsAt,
        ]));
        $periodStart = now()->startOfSecond();
        $periodEnd = $periodStart->copy()->addMonthNoOverflow();

        app(StripeBillingWebhookService::class)->process([
            'id' => 'evt_invoice_zero_trial_001',
            'type' => 'invoice.paid',
            'data' => ['object' => [
                'id' => 'in_zero_trial_001',
                'customer' => $customer->provider_customer_id,
                'subscription' => $subscription->provider_subscription_id,
                'status' => 'paid',
                'currency' => 'gbp',
                'amount_due' => 0,
                'amount_paid' => 0,
                'amount_remaining' => 0,
                'period_start' => $periodStart->timestamp,
                'period_end' => $periodEnd->timestamp,
                'status_transitions' => ['paid_at' => $periodStart->timestamp],
            ]],
        ], '{"event":"zero-trial"}');

        $this->assertSame(GymStatus::Trial, $gym->fresh()->status);
        $this->assertSame($trialEndsAt->timestamp, $gym->fresh()->trial_ends_at?->timestamp);
        app(TenantContext::class)->run($gym, fn () => $this->assertSame(
            SaasSubscriptionStatus::Trialing,
            $subscription->fresh()->status,
        ));

        app(StripeBillingWebhookService::class)->process([
            'id' => 'evt_invoice_paid_trial_001',
            'type' => 'invoice.paid',
            'data' => ['object' => [
                'id' => 'in_paid_trial_001',
                'customer' => $customer->provider_customer_id,
                'subscription' => $subscription->provider_subscription_id,
                'status' => 'paid',
                'currency' => 'gbp',
                'amount_due' => 7900,
                'amount_paid' => 7900,
                'amount_remaining' => 0,
                'period_start' => $periodStart->timestamp,
                'period_end' => $periodEnd->timestamp,
                'status_transitions' => ['paid_at' => $periodStart->timestamp],
            ]],
        ], '{"event":"paid-trial"}');

        $this->assertSame(GymStatus::Active, $gym->fresh()->status);
        $this->assertNull($gym->fresh()->trial_ends_at);
        app(TenantContext::class)->run($gym, function () use ($subscription, $periodStart, $periodEnd): void {
            $fresh = $subscription->fresh();
            $this->assertSame(SaasSubscriptionStatus::Active, $fresh->status);
            $this->assertNull($fresh->trial_ends_at);
            $this->assertSame($periodStart->timestamp, $fresh->current_period_start?->timestamp);
            $this->assertSame($periodEnd->timestamp, $fresh->current_period_end?->timestamp);
        });
    }

    public function test_manual_suspension_is_not_overwritten_by_a_paid_invoice(): void
    {
        [, $gym] = $this->tenant(UserRole::GymOwner);
        [$plan, $price] = $this->catalogue(['stripe']);
        [$customer, $subscription] = $this->stripeSubscription($gym, $plan, $price);
        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/gyms/{$gym->id}", [
            'status' => GymStatus::Suspended->value,
            'reason' => 'Manual risk suspension remains authoritative.',
        ], ['X-Gym-ID' => $gym->id])->assertSuccessful()->assertJsonPath('data.status', 'suspended');

        $periodStart = now()->startOfSecond();
        $periodEnd = $periodStart->copy()->addMonthNoOverflow();
        app(StripeBillingWebhookService::class)->process([
            'id' => 'evt_invoice_paid_suspended_001',
            'type' => 'invoice.paid',
            'data' => ['object' => [
                'id' => 'in_paid_suspended_001',
                'customer' => $customer->provider_customer_id,
                'subscription' => $subscription->provider_subscription_id,
                'status' => 'paid',
                'currency' => 'gbp',
                'amount_due' => 7900,
                'amount_paid' => 7900,
                'amount_remaining' => 0,
                'period_start' => $periodStart->timestamp,
                'period_end' => $periodEnd->timestamp,
                'status_transitions' => ['paid_at' => $periodStart->timestamp],
            ]],
        ], '{"event":"paid-suspended"}');

        $this->assertSame(GymStatus::Suspended, $gym->fresh()->status);
        app(TenantContext::class)->run($gym, function () use ($subscription, $periodEnd): void {
            $fresh = $subscription->fresh();
            $this->assertSame(SaasSubscriptionStatus::Active, $fresh->status);
            $this->assertSame($periodEnd->timestamp, $fresh->current_period_end?->timestamp);
        });
    }

    public function test_plan_management_archives_safely_and_appends_price_history_without_stripe(): void
    {
        $this->disableStripe();
        Http::preventStrayRequests();
        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        Sanctum::actingAs($admin);
        $created = $this->postJson('/api/v1/platform/saas-plans', $this->planPayload('managed-flex'))
            ->assertSuccessful()->json('data');
        $oldPriceId = $created['prices'][0]['id'];

        $updated = $this->patchJson("/api/v1/platform/saas-plans/{$created['id']}", [
            'name' => 'Managed Flex Plus',
            'status' => 'archived',
            'payment_methods' => ['cash', 'bank_transfer'],
            'price' => [
                'currency' => 'GBP', 'billing_interval' => 'monthly',
                'amount_minor' => 8900, 'trial_days' => 7,
            ],
            'reason' => 'Catalogue lifecycle and immutable price verification',
        ])->assertSuccessful()->assertJsonPath('data.name', 'Managed Flex Plus')
            ->assertJsonPath('data.status', 'archived')->json('data');

        $this->assertCount(2, $updated['prices']);
        $this->assertDatabaseHas('saas_plan_prices', ['id' => $oldPriceId, 'active' => false]);
        $this->assertDatabaseHas('saas_plan_prices', ['saas_plan_id' => $created['id'], 'amount_minor' => 8900, 'active' => true]);
        $this->withDatabaseIdentity($admin, function () use ($admin): void {
            $this->assertDatabaseHas('audit_logs', ['event' => 'platform.saas_plan.updated', 'actor_id' => $admin->id]);
            $this->assertDatabaseHas('audit_logs', ['event' => 'platform.saas_price.created', 'actor_id' => $admin->id]);
        });
    }

    public function test_cash_subscription_uses_the_platform_manual_ledger(): void
    {
        [$owner, $gym] = $this->tenant(UserRole::GymOwner);
        [, $price] = $this->catalogue(['cash']);
        Sanctum::actingAs($owner);
        $created = $this->postJson(
            "/api/v1/gyms/{$gym->id}/saas-subscription/manual-payments",
            [
                'saas_plan_price_id' => $price->id,
                'method' => 'cash',
                'idempotency_key' => 'saas-cash-payment-00001',
                'reference' => 'CASH-RECEIPT-901',
            ],
            ['X-Gym-ID' => $gym->id],
        )->assertCreated()->assertJsonPath('data.status', 'pending');

        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        Sanctum::actingAs($admin);
        $this->patchJson(
            "/api/v1/gyms/{$gym->id}/saas-subscription/manual-payments/{$created->json('data.id')}/review",
            ['decision' => 'approve', 'reason' => 'Cash received and platform receipt checked.'],
            ['X-Gym-ID' => $gym->id],
        )->assertSuccessful()->assertJsonPath('data.status', 'paid');

        app(TenantContext::class)->run($gym, function (): void {
            $this->assertSame(1, GymSubscription::query()->where('provider', 'manual')->where('status', 'active')->count());
            $this->assertSame(1, SaasBillingInvoice::query()->where('status', 'paid')->count());
        });
        app(TenantContext::class)->run($gym, fn () => $this->assertSame(
            0,
            Payment::query()->withoutGlobalScopes()->count(),
        ));
    }

    public function test_stripe_is_synchronized_only_when_configured_card_checkout_is_selected(): void
    {
        $this->enableStripe();
        Http::fake(function (HttpRequest $request) {
            return match (true) {
                str_ends_with($request->url(), '/v1/products') => Http::response(['id' => 'prod_optional'], 200),
                str_ends_with($request->url(), '/v1/prices') => Http::response(['id' => 'price_optional'], 200),
                str_ends_with($request->url(), '/v1/customers') => Http::response(['id' => 'cus_optional'], 200),
                str_ends_with($request->url(), '/v1/checkout/sessions') => Http::response([
                    'id' => 'cs_optional',
                    'url' => 'https://checkout.stripe.test/cs_optional',
                    'expires_at' => now()->addHour()->timestamp,
                ], 200),
                default => Http::response([], 404),
            };
        });

        [$owner, $gym] = $this->tenant(UserRole::GymOwner);
        [$plan, $price] = $this->catalogue(['stripe']);
        $this->assertNull($plan->provider_product_id);
        $this->assertNull($price->provider_price_id);
        Sanctum::actingAs($owner);
        $this->postJson(
            "/api/v1/gyms/{$gym->id}/saas-subscription/checkout",
            [
                'saas_plan_price_id' => $price->id,
                'idempotency_key' => 'saas-stripe-checkout-0001',
            ],
            ['X-Gym-ID' => $gym->id],
        )->assertSuccessful()
            ->assertJsonPath('data.checkout_url', 'https://checkout.stripe.test/cs_optional');

        $this->assertSame('prod_optional', $plan->fresh()->provider_product_id);
        $this->assertSame('price_optional', $price->fresh()->provider_price_id);
        Http::assertSentCount(4);
        Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/v1/checkout/sessions')
            && ! $request->hasHeader('Stripe-Account')
        );
    }

    public function test_manual_recurring_invoice_grace_restriction_override_and_renewal_are_tenant_safe(): void
    {
        Queue::fake();
        [$owner, $gym] = $this->tenant(UserRole::GymOwner);
        [$plan, $price] = $this->catalogue(['cash', 'bank_transfer']);
        [$customer] = $this->stripeSubscription($gym, $plan, $price);
        app(TenantContext::class)->run($gym, function () use ($customer, $plan, $price): void {
            GymSubscription::query()->delete();
            GymSubscription::query()->create([
                'billing_customer_id' => $customer->id, 'saas_plan_id' => $plan->id,
                'saas_plan_price_id' => $price->id, 'provider' => PaymentProvider::Manual,
                'provider_subscription_id' => 'manual_recurring_contract', 'status' => SaasSubscriptionStatus::Active,
                'plan_code_snapshot' => $plan->code, 'plan_name_snapshot' => $plan->name,
                'feature_limits_snapshot' => $plan->feature_limits, 'currency' => Currency::GBP,
                'amount_minor' => 7900, 'billing_interval' => 'monthly',
                'current_period_start' => now()->subMonth(), 'current_period_end' => now()->startOfDay(),
                'next_billing_at' => now()->startOfDay(), 'grace_period_days' => 15,
            ]);
        });

        app(TenantContext::class)->run($gym, fn () => app(AutomatedSaasBillingService::class)->processTenant($gym));
        $invoice = app(TenantContext::class)->run($gym, fn () => SaasBillingInvoice::query()->where('status', 'due')->firstOrFail());
        app(TenantContext::class)->run($gym, fn () => $this->assertSame(1, SaasBillingNotification::query()->count()));

        $this->travel(16)->days();
        app(TenantContext::class)->run($gym, fn () => app(AutomatedSaasBillingService::class)->processTenant($gym));
        $subscription = app(TenantContext::class)->run($gym, fn () => GymSubscription::query()->latest()->firstOrFail());
        $this->assertNotNull($subscription->billing_restricted_at);
        $this->assertSame(SaasSubscriptionStatus::PastDue, $subscription->status);

        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/gyms/{$gym->id}/members", ['X-Gym-ID' => $gym->id])->assertStatus(402);

        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/gyms/{$gym->id}/saas-subscription/billing-override", [
            'days' => 7, 'reason' => 'Approved short operational extension.',
        ], ['X-Gym-ID' => $gym->id])->assertSuccessful()->assertJsonPath('data.status', 'active');

        Sanctum::actingAs($owner);
        $payment = $this->postJson("/api/v1/gyms/{$gym->id}/saas-subscription/manual-payments", [
            'saas_billing_invoice_id' => $invoice->id, 'method' => 'cash',
            'idempotency_key' => 'manual-renewal-payment-0001', 'reference' => 'CASH-RENEWAL-001',
        ], ['X-Gym-ID' => $gym->id])->assertCreated()->json('data');

        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/gyms/{$gym->id}/saas-subscription/manual-payments/{$payment['id']}/review", [
            'decision' => 'approve', 'reason' => 'Renewal cash verified by finance.',
        ], ['X-Gym-ID' => $gym->id])->assertSuccessful()->assertJsonPath('data.status', 'paid');
        app(TenantContext::class)->run($gym, function () use ($invoice): void {
            $this->assertSame(1, GymSubscription::query()->count());
            $this->assertSame('paid', $invoice->fresh()->status->value);
            $this->assertNull(GymSubscription::query()->latest()->firstOrFail()->billing_restricted_at);
        });
    }

    public function test_platform_insights_and_global_directory_require_super_admin_and_keep_gym_boundaries(): void
    {
        [$ownerA, $gymA] = $this->tenant(UserRole::GymOwner);
        [, $gymB] = $this->tenant(UserRole::GymOwner);
        app(TenantContext::class)->run($gymA, fn () => Member::query()->create([
            'member_number' => 'PLATFORM-A-001',
            'first_name' => 'Alpha',
            'last_name' => 'Member',
            'email' => 'alpha@example.test',
            'phone' => '+447700900101',
            'status' => 'active',
            'joined_at' => now(),
        ]));
        app(TenantContext::class)->run($gymB, fn () => Member::query()->create([
            'member_number' => 'PLATFORM-B-001',
            'first_name' => 'Beta',
            'last_name' => 'Member',
            'email' => 'beta@example.test',
            'phone' => '+447700900102',
            'status' => 'active',
            'joined_at' => now(),
        ]));

        Sanctum::actingAs($ownerA);
        $this->getJson('/api/v1/platform/member-directory')->assertForbidden();
        $this->getJson('/api/v1/platform/billing')->assertForbidden();

        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/platform/member-directory?gym_id={$gymA->id}")
            ->assertSuccessful()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.gym_id', $gymA->id)
            ->assertJsonMissing(['gym_id' => $gymB->id]);
        $this->getJson('/api/v1/platform/billing')->assertSuccessful()->assertJsonStructure(['data' => ['metrics', 'subscriptions', 'invoices']]);
        $this->getJson('/api/v1/platform/analytics')->assertSuccessful()->assertJsonStructure(['data' => ['timeline', 'plan_distribution', 'billing_metrics']]);
    }

    public function test_unpaid_saas_invoice_void_is_audited_and_cross_tenant_safe(): void
    {
        [, $gym] = $this->tenant(UserRole::GymOwner);
        [, $otherGym] = $this->tenant(UserRole::GymOwner);
        [$plan, $price] = $this->catalogue(['cash', 'bank_transfer']);
        [$customer, $subscription] = $this->stripeSubscription($gym, $plan, $price);
        $invoice = app(TenantContext::class)->run($gym, fn () => SaasBillingInvoice::query()->create([
            'billing_customer_id' => $customer->id,
            'gym_subscription_id' => $subscription->id,
            'provider_invoice_id' => 'manual-void-contract',
            'number' => 'IC-VOID-001',
            'status' => 'due',
            'currency' => Currency::GBP,
            'amount_due_minor' => 7900,
            'amount_paid_minor' => 0,
            'amount_remaining_minor' => 7900,
            'period_start' => now(),
            'period_end' => now()->addMonth(),
            'due_at' => now(),
        ]));

        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/gyms/{$gym->id}/saas-billing-invoices/{$invoice->id}/void", [
            'reason' => 'Duplicate renewal invoice confirmed by platform finance.',
        ], ['X-Gym-ID' => $gym->id])->assertSuccessful()
            ->assertJsonPath('data.status', 'void');
        app(TenantContext::class)->run($gym, function () use ($gym, $invoice): void {
            $this->assertDatabaseHas('saas_billing_invoices', [
                'gym_id' => $gym->id, 'id' => $invoice->id, 'status' => 'void',
            ]);
            $this->assertDatabaseHas('audit_logs', ['gym_id' => $gym->id, 'event' => 'saas.invoice.voided']);
        });
        $this->postJson("/api/v1/gyms/{$otherGym->id}/saas-billing-invoices/{$invoice->id}/void", [
            'reason' => 'Attempted cross tenant invoice void.',
        ], ['X-Gym-ID' => $otherGym->id])->assertNotFound();
    }

    /** @return array{User, Gym} */
    private function tenant(UserRole $role): array
    {
        $user = User::factory()->create();
        $gym = Gym::factory()->create(['base_currency' => Currency::GBP]);
        app(TenantContext::class)->run($gym, fn () => $gym->users()->attach($user, [
            'role' => $role->value,
            'status' => 'active',
        ]));

        return [$user, $gym];
    }

    /** @param list<string> $methods @return array{SaasPlan, SaasPlanPrice} */
    private function catalogue(array $methods): array
    {
        $plan = SaasPlan::query()->create([
            'code' => 'manual-'.implode('-', $methods),
            'name' => 'Manual Growth',
            'status' => 'active',
            'feature_limits' => [
                'members' => 2500,
                'branches' => 3,
                'staff' => 30,
                'advanced_reports' => true,
                'priority_support' => false,
            ],
            'payment_methods' => $methods,
        ]);
        $price = SaasPlanPrice::query()->create([
            'saas_plan_id' => $plan->id,
            'currency' => Currency::GBP,
            'billing_interval' => 'monthly',
            'amount_minor' => 7900,
            'trial_days' => 14,
            'active' => true,
        ]);

        return [$plan, $price];
    }

    /** @return array{PlatformBillingCustomer, GymSubscription} */
    private function stripeSubscription(Gym $gym, SaasPlan $plan, SaasPlanPrice $price): array
    {
        return app(TenantContext::class)->run($gym, function () use ($gym, $plan, $price): array {
            $customer = PlatformBillingCustomer::query()->create([
                'provider' => PaymentProvider::Stripe,
                'provider_customer_id' => 'cus_status_'.substr($gym->id, 0, 8),
                'billing_email' => 'billing-'.$gym->id.'@example.test',
                'billing_name' => $gym->name,
                'country_code' => 'GB',
                'default_currency' => Currency::GBP,
            ]);
            $subscription = GymSubscription::query()->create([
                'billing_customer_id' => $customer->id,
                'saas_plan_id' => $plan->id,
                'saas_plan_price_id' => $price->id,
                'provider' => PaymentProvider::Stripe,
                'provider_subscription_id' => 'sub_status_'.substr($gym->id, 0, 8),
                'status' => SaasSubscriptionStatus::Active,
                'plan_code_snapshot' => $plan->code,
                'plan_name_snapshot' => $plan->name,
                'feature_limits_snapshot' => $plan->feature_limits,
                'currency' => Currency::GBP,
                'amount_minor' => $price->amount_minor,
                'billing_interval' => $price->billing_interval,
                'current_period_start' => now()->subMonth(),
                'current_period_end' => now(),
            ]);

            return [$customer, $subscription];
        });
    }

    /** @return array<string, mixed> */
    private function planPayload(string $code): array
    {
        return [
            'code' => $code,
            'name' => 'Local Flex',
            'description' => 'Works without a card provider.',
            'currency' => 'GBP',
            'billing_interval' => 'monthly',
            'amount_minor' => 7900,
            'trial_days' => 14,
            'payment_methods' => ['bank_transfer', 'cash'],
            'feature_limits' => [
                'members' => 2500,
                'branches' => 3,
                'staff' => 30,
                'advanced_reports' => true,
                'priority_support' => false,
            ],
        ];
    }

    private function disableStripe(): void
    {
        config([
            'services.stripe.secret' => null,
            'services.stripe.api_url' => null,
            'services.stripe.billing_webhook_secret' => null,
            'services.stripe.billing_checkout_success_url' => null,
            'services.stripe.billing_checkout_cancel_url' => null,
        ]);
    }

    private function withDatabaseIdentity(User $user, callable $callback): mixed
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return $callback();
        }

        // Platform audit RLS is bound to the authenticated identity just like
        // BindDatabaseIdentity does for a real request.
        DB::statement("select set_config('ironcore.current_user_id', ?, false)", [$user->id]);
        try {
            return $callback();
        } finally {
            DB::statement("select set_config('ironcore.current_user_id', '', false)");
        }
    }

    private function enableStripe(): void
    {
        config([
            'services.stripe.secret' => 'sk_test_optional',
            'services.stripe.api_url' => 'https://api.stripe.test',
            'services.stripe.billing_webhook_secret' => 'whsec_optional',
            'services.stripe.billing_checkout_success_url' => 'https://app.example.test/billing/success?gym={GYM_ID}',
            'services.stripe.billing_checkout_cancel_url' => 'https://app.example.test/billing/cancel?gym={GYM_ID}',
        ]);
    }
}
