<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Gym;
use App\Models\GymSubscription;
use App\Models\Payment;
use App\Models\SaasBillingInvoice;
use App\Models\SaasPlan;
use App\Models\SaasPlanPrice;
use App\Models\SaasSubscriptionPayment;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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
        [, $otherGym] = $this->tenant(UserRole::GymOwner);
        [$plan, $price] = $this->catalogue(['bank_transfer']);
        Sanctum::actingAs($owner);

        $created = $this->post(
            "/api/v1/gyms/{$gym->id}/saas-subscription/manual-payments",
            [
                'saas_plan_price_id' => $price->id,
                'method' => 'bank_transfer',
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
        });
        app(TenantContext::class)->run($gym, function (): void {
            // The SaaS ledger and gym-member payment ledger stay separate, and
            // their tenant audit evidence remains behind forced RLS.
            $this->assertSame(0, Payment::query()->withoutGlobalScopes()->count());
            $this->assertTrue(AuditLog::query()->where('event', 'saas.subscription_payment.approved')->exists());
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
        Http::assertSent(fn (HttpRequest $request): bool =>
            str_ends_with($request->url(), '/v1/checkout/sessions')
            && ! $request->hasHeader('Stripe-Account')
        );
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
