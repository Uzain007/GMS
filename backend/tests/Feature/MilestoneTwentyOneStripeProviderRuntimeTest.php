<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\MemberStatus;
use App\Enums\PaymentGatewayStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\SaasSubscriptionStatus;
use App\Enums\SubscriptionCheckoutStatus;
use App\Enums\UserRole;
use App\Models\Gym;
use App\Models\GymSubscription;
use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\PlatformBillingCustomer;
use App\Models\SaasPlan;
use App\Models\SaasPlanPrice;
use App\Models\SubscriptionCheckoutSession;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\SaasBillingService;
use App\Services\StripeBillingWebhookService;
use App\Services\StripeGatewayService;
use App\Services\StripePlatformBillingService;
use App\Services\StripeProviderException;
use App\Services\StripeWebhookService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class MilestoneTwentyOneStripeProviderRuntimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var(env('IRONCORE_STRIPE_RUNTIME_GATE', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('The Stripe transport runtime assertions run only in the explicit CI gate.');
        }

        // Provider evidence is reset for every test so request counts cannot
        // become cross-test authority for money-flow or tenant assertions.
        $this->providerRequest()->post($this->evidenceBaseUrl().'/_reset')->throw();
    }

    public function test_connect_and_platform_billing_cross_https_with_separate_money_flows(): void
    {
        [$gym, $owner, $member] = $this->tenant('PRIMARY');
        $context = app(TenantContext::class);

        [$payment, $gateway] = $context->run($gym, function () use ($gym, $owner, $member): array {
            $onboarding = app(StripeGatewayService::class)->startOnboarding($gym, $owner->email);
            $this->assertSame(PaymentGatewayStatus::Active, $onboarding['gateway']->status);
            $this->assertSame('https://connect.stripe.test/ironcore-onboarding', $onboarding['onboarding_url']);

            $gateway = app(StripeGatewayService::class)->refresh($onboarding['gateway']);
            $result = app(PaymentService::class)->create([
                'member_id' => $member->id,
                'method' => PaymentMethod::OnlineCard->value,
                'amount_minor' => 5500,
                'currency' => Currency::GBP->value,
                'idempotency_key' => 'runtime-payment-001',
            ], $owner, Request::create('/runtime/payment', 'POST'));

            $this->assertFalse($result['reused']);
            $this->assertSame('https://checkout.stripe.test/cs_ci_payment_1', $result['checkout_url']);
            $this->assertSame(PaymentStatus::Pending, $result['payment']->status);
            return [$result['payment'], $gateway];
        });

        $connectEvent = [
            'id' => 'evt_ci_connect_success_1',
            'type' => 'checkout.session.completed',
            'account' => $gateway->provider_account_id,
            'data' => ['object' => [
                'id' => $payment->provider_checkout_id,
                'payment_intent' => 'pi_ci_payment_1',
                'metadata' => ['gym_id' => $gym->id, 'payment_id' => $payment->id],
            ]],
        ];
        $this->postSignedWebhook('/api/v1/webhooks/stripe', $connectEvent, (string) config('services.stripe.webhook_secret'))
            ->assertOk()->assertJson(['received' => true, 'duplicate' => false]);
        $this->postSignedWebhook('/api/v1/webhooks/stripe', $connectEvent, (string) config('services.stripe.webhook_secret'))
            ->assertOk()->assertJson(['received' => true, 'duplicate' => true]);

        $refund = $context->run($gym, function () use ($payment, $owner): PaymentRefund {
            $settled = $payment->fresh();
            $this->assertSame(PaymentStatus::Succeeded, $settled->status);
            $this->assertSame('pi_ci_payment_1', $settled->provider_payment_id);
            return app(PaymentService::class)->refund($settled, [
                'amount_minor' => 500,
                'reason' => 'Credential-free runtime refund',
            ], $owner, Request::create('/runtime/refund', 'POST'));
        });
        $this->assertSame(RefundStatus::Succeeded, $refund->status);
        $this->assertSame('re_ci_1', $refund->provider_refund_id);

        $provider = app(StripePlatformBillingService::class)->createProductAndPrice(
            $this->planData(),
            $this->priceData(),
        );
        [$plan, $price] = $this->platformPlan($provider);

        [$checkout, $customer, $session, $portal] = $context->run(
            $gym,
            function () use ($gym, $owner, $price): array {
                $checkout = app(SaasBillingService::class)->startCheckout(
                    $gym,
                    $price,
                    'runtime-saas-checkout-001',
                    $owner,
                );
                return [
                    $checkout,
                    PlatformBillingCustomer::query()->firstOrFail(),
                    SubscriptionCheckoutSession::query()->firstOrFail(),
                    app(SaasBillingService::class)->createPortal(),
                ];
            },
        );
        $this->assertFalse($checkout['idempotency_reused']);
        $this->assertSame('https://checkout.stripe.test/cs_ci_saas_1', $checkout['checkout_url']);
        $this->assertSame('https://billing.stripe.test/bps_ci_1', $portal['portal_url']);

        $billingEvent = [
            'id' => 'evt_ci_billing_success_1',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => $session->provider_session_id,
                'customer' => $customer->provider_customer_id,
                'client_reference_id' => $gym->id,
                'subscription' => 'sub_ci_1',
                'metadata' => ['gym_id' => $gym->id, 'saas_plan_price_id' => $price->id],
            ]],
        ];
        $this->postSignedWebhook('/api/v1/webhooks/stripe/billing', $billingEvent, (string) config('services.stripe.billing_webhook_secret'))
            ->assertOk()->assertJson(['received' => true, 'duplicate' => false]);
        $this->postSignedWebhook('/api/v1/webhooks/stripe/billing', $billingEvent, (string) config('services.stripe.billing_webhook_secret'))
            ->assertOk()->assertJson(['received' => true, 'duplicate' => true]);

        $context->run($gym, function () use ($session, $plan, $price): void {
            $this->assertSame(SubscriptionCheckoutStatus::Completed, $session->fresh()->status);
            $subscription = GymSubscription::query()->firstOrFail();
            $this->assertSame(SaasSubscriptionStatus::Trialing, $subscription->status);
            $this->assertSame($plan->id, $subscription->saas_plan_id);
            $this->assertSame($price->id, $subscription->saas_plan_price_id);
            $this->assertSame(7900, $subscription->amount_minor);
            $this->assertSame(Currency::GBP, $subscription->currency);
        });

        $requests = collect($this->evidence()['requests']);
        $memberCheckout = $requests->first(
            fn (array $request): bool => $request['path'] === '/v1/checkout/sessions'
                && ($request['body']['mode'] ?? null) === 'payment',
        );
        $saasCheckout = $requests->first(
            fn (array $request): bool => $request['path'] === '/v1/checkout/sessions'
                && ($request['body']['mode'] ?? null) === 'subscription',
        );
        $refundRequest = $requests->firstWhere('path', '/v1/refunds');

        $this->assertSame($gateway->provider_account_id, $memberCheckout['stripe_account']);
        $this->assertSame('payment:'.$payment->id, $memberCheckout['idempotency_key']);
        $this->assertSame('5500', $memberCheckout['body']['line_items[0][price_data][unit_amount]']);
        $this->assertSame($gym->id, $memberCheckout['body']['metadata[gym_id]']);
        $this->assertNull($saasCheckout['stripe_account']);
        $this->assertSame('saas-checkout:'.$gym->id.':runtime-saas-checkout-001', $saasCheckout['idempotency_key']);
        $this->assertSame($customer->provider_customer_id, $saasCheckout['body']['customer']);
        $this->assertSame($price->provider_price_id, $saasCheckout['body']['line_items[0][price]']);
        $this->assertSame($gateway->provider_account_id, $refundRequest['stripe_account']);
        $this->assertSame('500', $refundRequest['body']['amount']);

        foreach (['/v1/products', '/v1/prices', '/v1/customers', '/v1/billing_portal/sessions'] as $platformPath) {
            $this->assertNull($requests->firstWhere('path', $platformPath)['stripe_account']);
        }
    }

    public function test_signed_webhooks_deny_cross_tenant_metadata_and_use_distinct_secrets(): void
    {
        [$selectedGym, $selectedOwner] = $this->tenant('SELECTED');
        [$otherGym, $otherOwner, $otherMember] = $this->tenant('OTHER');
        $context = app(TenantContext::class);

        $gateway = $context->run(
            $selectedGym,
            fn () => app(StripeGatewayService::class)->startOnboarding($selectedGym, $selectedOwner->email)['gateway'],
        );
        $otherPayment = $context->run($otherGym, fn (): Payment => Payment::query()->create([
            'member_id' => $otherMember->id,
            'recorded_by' => $otherOwner->id,
            'receipt_number' => 'PAY-RUNTIME-OTHER',
            'provider' => PaymentProvider::Stripe,
            'method' => PaymentMethod::OnlineCard,
            'status' => PaymentStatus::Pending,
            'amount_minor' => 2500,
            'currency' => Currency::GBP,
            'idempotency_key' => 'runtime-other-payment',
        ]));

        $connectEvent = [
            'id' => 'evt_ci_connect_cross_tenant',
            'type' => 'checkout.session.completed',
            'account' => $gateway->provider_account_id,
            'data' => ['object' => [
                'payment_intent' => 'pi_must_not_set',
                'metadata' => ['gym_id' => $otherGym->id, 'payment_id' => $otherPayment->id],
            ]],
        ];
        $connectPayload = $this->encoded($connectEvent);
        $verifiedConnect = app(StripeGatewayService::class)->verifyWebhook(
            $connectPayload,
            $this->signature($connectPayload, (string) config('services.stripe.webhook_secret')),
        );
        try {
            app(StripeWebhookService::class)->process($verifiedConnect, $connectPayload);
            $this->fail('Connect metadata crossed the resolved tenant boundary.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Stripe payment metadata does not match the resolved tenant.', $exception->getMessage());
        }
        $context->run($otherGym, function () use ($otherPayment): void {
            $this->assertSame(PaymentStatus::Pending, $otherPayment->fresh()->status);
            $this->assertNull($otherPayment->fresh()->provider_payment_id);
        });

        $provider = app(StripePlatformBillingService::class)->createProductAndPrice($this->planData(), $this->priceData());
        [, $price] = $this->platformPlan($provider);
        [$customer, $otherSession] = $context->run($otherGym, function () use ($otherGym, $otherOwner, $price): array {
            app(SaasBillingService::class)->startCheckout($otherGym, $price, 'runtime-cross-saas', $otherOwner);
            return [
                PlatformBillingCustomer::query()->firstOrFail(),
                SubscriptionCheckoutSession::query()->firstOrFail(),
            ];
        });

        $billingEvent = [
            'id' => 'evt_ci_billing_cross_tenant',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => $otherSession->provider_session_id,
                'customer' => $customer->provider_customer_id,
                'subscription' => 'sub_must_not_create',
                'metadata' => ['gym_id' => $selectedGym->id, 'saas_plan_price_id' => $price->id],
            ]],
        ];
        $billingPayload = $this->encoded($billingEvent);
        $verifiedBilling = app(StripePlatformBillingService::class)->verifyBillingWebhook(
            $billingPayload,
            $this->signature($billingPayload, (string) config('services.stripe.billing_webhook_secret')),
        );
        try {
            app(StripeBillingWebhookService::class)->process($verifiedBilling, $billingPayload);
            $this->fail('Billing metadata crossed the resolved tenant boundary.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Stripe subscription checkout metadata does not match the resolved tenant.', $exception->getMessage());
        }
        $context->run($otherGym, function () use ($otherSession): void {
            $this->assertSame(SubscriptionCheckoutStatus::Open, $otherSession->fresh()->status);
            $this->assertSame(0, GymSubscription::query()->count());
        });

        $this->postSignedWebhook(
            '/api/v1/webhooks/stripe',
            $connectEvent,
            (string) config('services.stripe.billing_webhook_secret'),
        )->assertBadRequest()->assertJsonPath('message', 'The Stripe webhook signature is invalid.');
        $this->postSignedWebhook(
            '/api/v1/webhooks/stripe/billing',
            $billingEvent,
            (string) config('services.stripe.webhook_secret'),
        )->assertBadRequest()->assertJsonPath('message', 'The Stripe Billing webhook signature is invalid.');
    }

    public function test_provider_failure_is_sanitized_before_ledger_evidence(): void
    {
        [$gym, $owner, $member] = $this->tenant('REJECT');
        $context = app(TenantContext::class);
        $exception = null;

        $refund = $context->run($gym, function () use ($gym, $owner, $member, &$exception): PaymentRefund {
            app(StripeGatewayService::class)->startOnboarding($gym, $owner->email);
            $payment = Payment::query()->create([
                'member_id' => $member->id,
                'recorded_by' => $owner->id,
                'receipt_number' => 'PAY-RUNTIME-REJECT',
                'provider' => PaymentProvider::Stripe,
                'method' => PaymentMethod::OnlineCard,
                'status' => PaymentStatus::Succeeded,
                'amount_minor' => 20000,
                'currency' => Currency::GBP,
                'idempotency_key' => 'runtime-rejected-refund',
                'provider_payment_id' => 'pi_ci_rejected_refund',
                'paid_at' => now(),
            ]);

            try {
                app(PaymentService::class)->refund($payment, [
                    'amount_minor' => 9999,
                    'reason' => 'Exercise provider rejection sanitization',
                ], $owner, Request::create('/runtime/rejected-refund', 'POST'));
                $this->fail('The disposable Stripe rejection was accepted.');
            } catch (StripeProviderException $caught) {
                $exception = $caught;
            }

            return PaymentRefund::query()->where('payment_id', $payment->id)->firstOrFail();
        });

        $this->assertInstanceOf(StripeProviderException::class, $exception);
        $this->assertSame('The Stripe provider rejected the request.', $exception->getMessage());
        $this->assertNull($exception->getPrevious());
        foreach ([
            config('services.stripe.runtime_gate.rejection_marker'),
            config('services.stripe.api_url'),
            config('services.stripe.secret'),
            'pi_ci_rejected_refund',
        ] as $sensitiveValue) {
            $this->assertStringNotContainsString((string) $sensitiveValue, (string) $exception);
        }
        $this->assertSame(RefundStatus::Failed, $refund->status);
        $this->assertSame('provider_refund_failed', $refund->failure_code);
        $this->assertSame('The Stripe provider rejected the request.', $refund->failure_message);
        $this->assertSame(1, $this->evidence()['rejections']);
    }

    /** @return array{Gym, User, Member} */
    private function tenant(string $suffix): array
    {
        $gym = Gym::factory()->create([
            'name' => 'Runtime '.$suffix,
            'base_currency' => Currency::GBP,
            'country_code' => 'GB',
        ]);
        $owner = User::factory()->create(['email' => strtolower($suffix).'@runtime.test']);
        [$member] = app(TenantContext::class)->run($gym, function () use ($gym, $owner, $suffix): array {
            $gym->users()->attach($owner, ['role' => UserRole::GymOwner->value, 'status' => 'active']);
            return [Member::query()->create([
                'member_number' => 'RUNTIME-'.$suffix,
                'first_name' => 'Runtime',
                'last_name' => $suffix,
                'email' => strtolower($suffix).'-member@runtime.test',
                'status' => MemberStatus::Active,
            ])];
        });
        return [$gym, $owner, $member];
    }

    /**
     * @param  array{product_id: string, price_id: string}  $provider
     * @return array{SaasPlan, SaasPlanPrice}
     */
    private function platformPlan(array $provider): array
    {
        $plan = SaasPlan::query()->create([
            'code' => 'runtime-growth',
            'name' => 'Runtime Growth',
            'description' => 'Synthetic provider transport plan',
            'status' => 'active',
            'feature_limits' => $this->planData()['feature_limits'],
            'provider' => PaymentProvider::Stripe,
            'provider_product_id' => $provider['product_id'],
        ]);
        $price = SaasPlanPrice::query()->create([
            'saas_plan_id' => $plan->id,
            'currency' => Currency::GBP,
            'billing_interval' => 'monthly',
            'amount_minor' => 7900,
            'trial_days' => 14,
            'active' => true,
            'provider' => PaymentProvider::Stripe,
            'provider_price_id' => $provider['price_id'],
        ]);
        return [$plan, $price];
    }

    /** @return array<string, mixed> */
    private function planData(): array
    {
        return [
            'code' => 'runtime-growth',
            'name' => 'Runtime Growth',
            'description' => 'Synthetic provider transport plan',
            'feature_limits' => [
                'members' => 2500,
                'branches' => 3,
                'staff' => 30,
                'advanced_reports' => true,
                'priority_support' => false,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function priceData(): array
    {
        return [
            'currency' => Currency::GBP->value,
            'billing_interval' => 'monthly',
            'amount_minor' => 7900,
            'trial_days' => 14,
        ];
    }

    private function postSignedWebhook(string $path, array $event, string $secret): \Illuminate\Testing\TestResponse
    {
        $payload = $this->encoded($event);
        return $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $this->signature($payload, $secret),
        ], $payload);
    }

    private function signature(string $payload, string $secret): string
    {
        $timestamp = time();
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }

    private function encoded(array $event): string
    {
        return json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return array{requests: array<int, array<string, mixed>>, rejections: int} */
    private function evidence(): array
    {
        return $this->providerRequest()->get($this->evidenceBaseUrl().'/_evidence')->throw()->json();
    }

    private function providerRequest(): PendingRequest
    {
        return Http::withToken((string) config('services.stripe.runtime_gate.evidence_token'))
            ->withOptions(['verify' => (string) config('services.stripe.ca_bundle')])
            ->timeout(5);
    }

    private function evidenceBaseUrl(): string
    {
        return rtrim((string) config('services.stripe.runtime_gate.evidence_url'), '/');
    }
}
