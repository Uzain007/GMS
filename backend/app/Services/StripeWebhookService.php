<?php

namespace App\Services;

use App\Enums\PaymentProvider;
use App\Models\Gym;
use App\Models\PaymentGatewayAccount;
use App\Models\PaymentWebhookEvent;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class StripeWebhookService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly StripeGatewayService $stripe,
        private readonly PaymentService $payments,
    ) {}

    /** @return array{duplicate: bool} */
    public function process(array $event, string $rawPayload): array
    {
        $accountId = trim((string) ($event['account'] ?? ''));
        if ($accountId === '') {
            throw new RuntimeException('A connected Stripe account is required.');
        }

        $gymId = $this->resolveGymId($accountId);
        $gym = Gym::query()->find($gymId);
        if (! $gym) {
            throw new RuntimeException('The connected payment account is not recognised.');
        }

        return $this->context->run($gym, function () use ($event, $rawPayload, $accountId): array {
            $record = PaymentWebhookEvent::query()->firstOrCreate(
                ['provider' => PaymentProvider::Stripe->value, 'provider_event_id' => (string) $event['id']],
                [
                    'provider_account_id' => $accountId,
                    'event_type' => (string) $event['type'],
                    // Retain a proof hash, not a payload that may contain payer data.
                    'payload_hash' => hash('sha256', $rawPayload),
                    'status' => 'processing',
                ],
            );

            if (! $record->wasRecentlyCreated && $record->status === 'processed') {
                return ['duplicate' => true];
            }

            try {
                $this->applyEvent($event, $accountId);
                $record->update(['status' => 'processed', 'processed_at' => now(), 'error' => null]);
            } catch (Throwable $exception) {
                $record->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 2000)]);
                throw $exception;
            }

            return ['duplicate' => false];
        });
    }

    private function applyEvent(array $event, string $accountId): void
    {
        $type = (string) $event['type'];
        $object = $event['data']['object'] ?? [];
        if (! is_array($object)) {
            throw new RuntimeException('The Stripe event object is invalid.');
        }

        if ($type === 'account.updated') {
            $gateway = PaymentGatewayAccount::query()
                ->where('provider', PaymentProvider::Stripe->value)
                ->where('provider_account_id', $accountId)
                ->firstOrFail();
            $this->stripe->syncFromProviderPayload($gateway, $object);
            return;
        }

        $metadata = $object['metadata'] ?? [];
        $paymentId = trim((string) ($metadata['payment_id'] ?? ''));
        $gymId = trim((string) ($metadata['gym_id'] ?? ''));
        if ($paymentId === '' || $gymId === '' || ! hash_equals(mb_strtolower($this->context->id()), mb_strtolower($gymId))) {
            throw new RuntimeException('Stripe payment metadata does not match the resolved tenant.');
        }

        if ($type === 'checkout.session.completed') {
            $this->payments->markCheckoutSucceeded(
                $paymentId,
                isset($object['payment_intent']) ? (string) $object['payment_intent'] : null,
            );
            return;
        }

        if (in_array($type, ['checkout.session.async_payment_failed', 'checkout.session.expired'], true)) {
            $this->payments->markCheckoutFailed(
                $paymentId,
                $type === 'checkout.session.expired' ? 'checkout_expired' : 'asynchronous_payment_failed',
                $type === 'checkout.session.expired' ? 'The online checkout expired.' : 'The online payment failed.',
            );
        }
    }

    private function resolveGymId(string $providerAccountId): string
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        if ($pgsql) {
            // This setting activates a SELECT-only RLS policy for exactly one
            // opaque account row after the signature has been verified.
            DB::statement("select set_config('ironcore.current_provider_account_id', ?, false)", [$providerAccountId]);
        }

        try {
            $row = DB::table('payment_gateway_accounts')
                ->where('provider', PaymentProvider::Stripe->value)
                ->where('provider_account_id', $providerAccountId)
                ->first(['gym_id']);
        } finally {
            if ($pgsql) {
                DB::statement("select set_config('ironcore.current_provider_account_id', '', false)");
            }
        }

        if (! $row) {
            throw new RuntimeException('The connected payment account is not recognised.');
        }
        return (string) $row->gym_id;
    }
}
