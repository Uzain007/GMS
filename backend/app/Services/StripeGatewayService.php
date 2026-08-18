<?php

namespace App\Services;

use App\Enums\PaymentGatewayStatus;
use App\Enums\PaymentProvider;
use App\Models\Gym;
use App\Models\Payment;
use App\Models\PaymentGatewayAccount;
use App\Models\PaymentRefund;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class StripeGatewayService
{
    /** @return array{gateway: PaymentGatewayAccount, onboarding_url: string} */
    public function startOnboarding(Gym $gym, string $email): array
    {
        $this->assertConfigured(['connect_refresh_url', 'connect_return_url']);
        $gateway = PaymentGatewayAccount::query()->firstOrCreate(
            ['provider' => PaymentProvider::Stripe->value],
            [
                'status' => PaymentGatewayStatus::Pending->value,
                'country_code' => $gym->country_code,
                'default_currency' => $gym->base_currency->value,
            ],
        );

        if (! $gateway->provider_account_id) {
            $account = $this->post($this->platformRequest(), '/v1/accounts', [
                'type' => 'express',
                'country' => $gym->country_code,
                'email' => $email,
                'capabilities' => [
                    'card_payments' => ['requested' => true],
                    'transfers' => ['requested' => true],
                ],
                // gym_id is server-authored and helps reconcile signed events;
                // it never replaces the account-to-tenant RLS lookup.
                'metadata' => ['gym_id' => $gym->getKey()],
                'business_profile' => ['product_description' => 'Gym membership and joining-fee payments'],
            ]);
            $gateway->provider_account_id = (string) $account['id'];
            $this->syncFromProviderPayload($gateway, $account);
        }

        $link = $this->post($this->platformRequest(), '/v1/account_links', [
            'account' => $gateway->provider_account_id,
            'refresh_url' => config('services.stripe.connect_refresh_url'),
            'return_url' => config('services.stripe.connect_return_url'),
            'type' => 'account_onboarding',
        ]);

        return ['gateway' => $gateway->fresh(), 'onboarding_url' => (string) $link['url']];
    }

    public function refresh(PaymentGatewayAccount $gateway): PaymentGatewayAccount
    {
        $this->assertConfigured();
        if (! $gateway->provider_account_id) {
            return $gateway;
        }

        $payload = $this->get($this->platformRequest(), '/v1/accounts/'.$gateway->provider_account_id);
        $this->syncFromProviderPayload($gateway, $payload);
        return $gateway->fresh();
    }

    /** @return array{checkout_id: string, checkout_url: string} */
    public function createCheckout(Payment $payment): array
    {
        $this->assertConfigured(['checkout_success_url', 'checkout_cancel_url']);
        $gateway = PaymentGatewayAccount::query()
            ->where('provider', PaymentProvider::Stripe->value)
            ->first();

        if (! $gateway || $gateway->status !== PaymentGatewayStatus::Active || ! $gateway->charges_enabled) {
            throw ValidationException::withMessages([
                'method' => ['Connect and activate Stripe before creating an online card checkout.'],
            ]);
        }

        $payment->loadMissing('member');
        $successUrl = str_replace('{PAYMENT_ID}', $payment->getKey(), (string) config('services.stripe.checkout_success_url'));
        $cancelUrl = str_replace('{PAYMENT_ID}', $payment->getKey(), (string) config('services.stripe.checkout_cancel_url'));
        $metadata = [
            'gym_id' => $payment->gym_id,
            'payment_id' => $payment->getKey(),
            'invoice_id' => $payment->invoice_id ?? '',
        ];

        // Direct charges place the transaction and funds on the gym's connected
        // account. IronCore stores no card number, CVC or reusable payment token.
        $payload = $this->post(
            $this->connectedRequest($gateway, 'payment:'.$payment->getKey()),
            '/v1/checkout/sessions',
            [
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'customer_email' => $payment->member?->email,
                'line_items' => [[
                    'price_data' => [
                        'currency' => mb_strtolower($payment->currency->value),
                        'unit_amount' => $payment->amount_minor,
                        'product_data' => ['name' => 'Gym membership payment '.$payment->receipt_number],
                    ],
                    'quantity' => 1,
                ]],
                'metadata' => $metadata,
                'payment_intent_data' => [
                    'metadata' => $metadata,
                    'description' => 'IronCore receipt '.$payment->receipt_number,
                ],
            ],
        );

        return ['checkout_id' => (string) $payload['id'], 'checkout_url' => (string) $payload['url']];
    }

    /** @return array{refund_id: string} */
    public function createRefund(Payment $payment, PaymentRefund $refund): array
    {
        $this->assertConfigured();
        $gateway = PaymentGatewayAccount::query()
            ->where('provider', PaymentProvider::Stripe->value)
            ->where('status', PaymentGatewayStatus::Active->value)
            ->firstOrFail();

        if (! $payment->provider_payment_id) {
            throw ValidationException::withMessages(['payment' => ['The online payment has no settled provider reference.']]);
        }

        $payload = $this->post(
            $this->connectedRequest($gateway, 'refund:'.$refund->getKey()),
            '/v1/refunds',
            [
                'payment_intent' => $payment->provider_payment_id,
                'amount' => $refund->amount_minor,
                'metadata' => [
                    'gym_id' => $payment->gym_id,
                    'payment_id' => $payment->getKey(),
                    'refund_id' => $refund->getKey(),
                ],
            ],
        );

        return ['refund_id' => (string) $payload['id']];
    }

    public function verifyWebhook(string $payload, string $signature): array
    {
        $secret = (string) config('services.stripe.webhook_secret');
        if ($secret === '') {
            throw new RuntimeException('Stripe webhook verification is not configured.');
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
            throw new RuntimeException('The Stripe webhook timestamp is invalid.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        $valid = collect($parts['v1'] ?? [])->contains(fn (string $candidate): bool => hash_equals($expected, $candidate));
        if (! $valid) {
            throw new RuntimeException('The Stripe webhook signature is invalid.');
        }

        $event = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($event) || empty($event['id']) || empty($event['type'])) {
            throw new RuntimeException('The Stripe webhook payload is invalid.');
        }
        return $event;
    }

    public function syncFromProviderPayload(PaymentGatewayAccount $gateway, array $payload): void
    {
        $charges = (bool) ($payload['charges_enabled'] ?? false);
        $payouts = (bool) ($payload['payouts_enabled'] ?? false);
        $submitted = (bool) ($payload['details_submitted'] ?? false);
        $gateway->fill([
            'status' => $charges && $payouts
                ? PaymentGatewayStatus::Active
                : ($submitted ? PaymentGatewayStatus::Restricted : PaymentGatewayStatus::Pending),
            'charges_enabled' => $charges,
            'payouts_enabled' => $payouts,
            'details_submitted' => $submitted,
            'requirements' => [
                'currently_due' => $payload['requirements']['currently_due'] ?? [],
                'eventually_due' => $payload['requirements']['eventually_due'] ?? [],
                'disabled_reason' => $payload['requirements']['disabled_reason'] ?? null,
            ],
            'connected_at' => $charges && $payouts ? ($gateway->connected_at ?? now()) : $gateway->connected_at,
        ])->save();
    }

    private function platformRequest(): PendingRequest
    {
        $request = Http::baseUrl((string) config('services.stripe.api_url'))
            ->asForm()
            ->acceptJson()
            ->withToken((string) config('services.stripe.secret'))
            ->timeout(20);

        $caBundle = config('services.stripe.ca_bundle');
        return is_string($caBundle) && trim($caBundle) !== ''
            ? $request->withOptions(['verify' => $caBundle])
            : $request;
    }

    private function connectedRequest(PaymentGatewayAccount $gateway, string $idempotencyKey): PendingRequest
    {
        return $this->platformRequest()->withHeaders([
            'Stripe-Account' => $gateway->provider_account_id,
            'Idempotency-Key' => $idempotencyKey,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function post(PendingRequest $request, string $path, array $data): array
    {
        try {
            $payload = $request->post($path, $data)->throw()->json();
            if (! is_array($payload)) {
                throw new RuntimeException('The Stripe response was invalid.');
            }
            return $payload;
        } catch (Throwable) {
            throw StripeProviderException::rejected();
        }
    }

    /** @return array<string, mixed> */
    private function get(PendingRequest $request, string $path): array
    {
        try {
            $payload = $request->get($path)->throw()->json();
            if (! is_array($payload)) {
                throw new RuntimeException('The Stripe response was invalid.');
            }
            return $payload;
        } catch (Throwable) {
            throw StripeProviderException::rejected();
        }
    }

    /**
     * Validate only the URLs needed by the current provider operation. Keeping
     * these checks operation-specific prevents a checkout setting from blocking
     * an otherwise valid connected-account refresh or audited refund.
     *
     * @param  list<string>  $operationKeys
     */
    private function assertConfigured(array $operationKeys = []): void
    {
        foreach (['secret', 'api_url', ...$operationKeys] as $key) {
            if (blank(config('services.stripe.'.$key))) {
                throw new RuntimeException('Stripe is not configured for this operation.');
            }
        }
    }
}
