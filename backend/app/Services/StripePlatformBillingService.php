<?php

namespace App\Services;

use App\Models\Gym;
use App\Models\PlatformBillingCustomer;
use App\Models\SaasPlan;
use App\Models\SaasPlanPrice;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class StripePlatformBillingService
{
    /** @return array{product_id: string, price_id: string} */
    public function createProductAndPrice(array $plan, array $price): array
    {
        $this->assertConfigured();
        $product = $this->platformRequest('saas-product:'.hash('sha256', mb_strtolower($plan['code'])))
            ->post('/v1/products', [
                'name' => $plan['name'],
                'description' => $plan['description'] ?? null,
                'metadata' => ['ironcore_plan_code' => $plan['code']],
            ])->throw()->json();

        return [
            'product_id' => (string) $product['id'],
            'price_id' => $this->createPrice((string) $product['id'], $plan['code'], $price),
        ];
    }

    public function createPriceForPlan(SaasPlan $plan, array $price): string
    {
        $this->assertConfigured();
        if (! $plan->provider_product_id) {
            throw new RuntimeException('The SaaS plan has no synchronized Stripe product.');
        }

        return $this->createPrice($plan->provider_product_id, $plan->code, $price);
    }

    /** @return array<string, mixed> */
    public function createCustomer(Gym $gym, string $email, string $name): array
    {
        $this->assertConfigured();
        return $this->platformRequest('saas-customer:gym:'.$gym->getKey())
            ->post('/v1/customers', [
                'email' => $email,
                'name' => $name,
                // Metadata is reconciliation evidence only. Signed webhook
                // customer lookup + tenant RLS remains the authority boundary.
                'metadata' => ['gym_id' => $gym->getKey()],
            ])->throw()->json();
    }

    /** @return array{session_id: string, checkout_url: string, expires_at: int|null} */
    public function createCheckout(
        Gym $gym,
        PlatformBillingCustomer $customer,
        SaasPlanPrice $price,
        string $idempotencyKey,
    ): array {
        $this->assertConfigured(['billing_checkout_success_url', 'billing_checkout_cancel_url']);
        if (! $price->provider_price_id) {
            throw new RuntimeException('The selected SaaS price is not synchronized with Stripe.');
        }

        $successUrl = str_replace('{GYM_ID}', (string) $gym->getKey(), (string) config('services.stripe.billing_checkout_success_url'));
        $cancelUrl = str_replace('{GYM_ID}', (string) $gym->getKey(), (string) config('services.stripe.billing_checkout_cancel_url'));
        $metadata = ['gym_id' => $gym->getKey(), 'saas_plan_price_id' => $price->getKey()];
        $subscriptionData = ['metadata' => $metadata];
        if ($price->trial_days > 0) {
            $subscriptionData['trial_period_days'] = $price->trial_days;
        }

        // This request runs on the platform account without connected-account
        // routing, so IronCore subscription funds never enter a gym account.
        $payload = $this->platformRequest('saas-checkout:'.$gym->getKey().':'.$idempotencyKey)
            ->post('/v1/checkout/sessions', [
                'mode' => 'subscription',
                'customer' => $customer->provider_customer_id,
                'client_reference_id' => $gym->getKey(),
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'line_items' => [[
                    'price' => $price->provider_price_id,
                    'quantity' => 1,
                ]],
                'metadata' => $metadata,
                'subscription_data' => $subscriptionData,
            ])->throw()->json();

        return [
            'session_id' => (string) $payload['id'],
            'checkout_url' => (string) $payload['url'],
            'expires_at' => isset($payload['expires_at']) ? (int) $payload['expires_at'] : null,
        ];
    }

    /** @return array{portal_url: string} */
    public function createPortal(PlatformBillingCustomer $customer): array
    {
        $this->assertConfigured(['billing_portal_return_url']);
        $payload = $this->platformRequest()
            ->post('/v1/billing_portal/sessions', [
                'customer' => $customer->provider_customer_id,
                'return_url' => config('services.stripe.billing_portal_return_url'),
            ])->throw()->json();

        return ['portal_url' => (string) $payload['url']];
    }

    /** @return array<string, mixed> */
    public function retrieveCheckout(string $sessionId): array
    {
        $this->assertConfigured();
        return $this->platformRequest()->get('/v1/checkout/sessions/'.$sessionId)->throw()->json();
    }

    /** @return array<string, mixed> */
    public function retrieveSubscription(string $subscriptionId): array
    {
        $this->assertConfigured();
        return $this->platformRequest()->get('/v1/subscriptions/'.$subscriptionId, [
            'expand' => ['items.data.price'],
        ])->throw()->json();
    }

    public function verifyBillingWebhook(string $payload, string $signature): array
    {
        $secret = (string) config('services.stripe.billing_webhook_secret');
        if ($secret === '') {
            throw new RuntimeException('Stripe Billing webhook verification is not configured.');
        }

        $parts = [];
        foreach (explode(',', $signature) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key && $value) {
                $parts[$key][] = $value;
            }
        }

        $timestamp = isset($parts['t'][0]) ? (int) $parts['t'][0] : 0;
        if ($timestamp <= 0 || abs(time() - $timestamp) > 300) {
            throw new RuntimeException('The Stripe Billing webhook timestamp is invalid.');
        }

        // Signature verification uses the untouched raw body and the endpoint-
        // specific secret before any tenant lookup or JSON-derived identifier.
        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        $valid = collect($parts['v1'] ?? [])->contains(
            fn (string $candidate): bool => hash_equals($expected, $candidate)
        );
        if (! $valid) {
            throw new RuntimeException('The Stripe Billing webhook signature is invalid.');
        }

        $event = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($event) || empty($event['id']) || empty($event['type'])) {
            throw new RuntimeException('The Stripe Billing webhook payload is invalid.');
        }
        return $event;
    }

    private function createPrice(string $productId, string $planCode, array $price): string
    {
        $interval = $price['billing_interval'] === 'yearly' ? 'year' : 'month';
        $fingerprint = implode(':', [
            $planCode,
            $price['currency'],
            $price['billing_interval'],
            $price['amount_minor'],
        ]);
        $payload = $this->platformRequest('saas-price:'.hash('sha256', $fingerprint))
            ->post('/v1/prices', [
                'product' => $productId,
                'currency' => mb_strtolower((string) $price['currency']),
                'unit_amount' => $price['amount_minor'],
                'recurring' => ['interval' => $interval],
                'metadata' => [
                    'ironcore_plan_code' => $planCode,
                    'ironcore_billing_interval' => $price['billing_interval'],
                ],
            ])->throw()->json();
        return (string) $payload['id'];
    }

    private function platformRequest(?string $idempotencyKey = null): PendingRequest
    {
        $request = Http::baseUrl((string) config('services.stripe.api_url'))
            ->asForm()
            ->acceptJson()
            ->withToken((string) config('services.stripe.secret'))
            ->timeout(20);
        return $idempotencyKey ? $request->withHeader('Idempotency-Key', $idempotencyKey) : $request;
    }

    /** @param list<string> $operationKeys */
    private function assertConfigured(array $operationKeys = []): void
    {
        foreach (['secret', 'api_url', ...$operationKeys] as $key) {
            if (blank(config('services.stripe.'.$key))) {
                throw new RuntimeException('Stripe Billing is not configured for this operation.');
            }
        }
    }
}
