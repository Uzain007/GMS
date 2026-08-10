<?php

namespace App\Services;

use App\Enums\Currency;
use App\Enums\GymStatus;
use App\Enums\PaymentProvider;
use App\Enums\SaasInvoiceStatus;
use App\Enums\SaasSubscriptionStatus;
use App\Enums\SubscriptionCheckoutStatus;
use App\Models\Gym;
use App\Models\GymSubscription;
use App\Models\PlatformBillingCustomer;
use App\Models\SaasBillingInvoice;
use App\Models\SaasBillingWebhookEvent;
use App\Models\SaasPlanPrice;
use App\Models\SubscriptionCheckoutSession;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class StripeBillingWebhookService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly StripePlatformBillingService $stripe,
    ) {}

    /** @return array{duplicate: bool} */
    public function process(array $event, string $rawPayload): array
    {
        $object = $event['data']['object'] ?? null;
        if (! is_array($object)) {
            throw new RuntimeException('The Stripe Billing event object is invalid.');
        }
        $customerId = $this->stringId($object['customer'] ?? null);
        if ($customerId === '') {
            throw new RuntimeException('A Stripe Billing customer is required.');
        }

        $gymId = $this->resolveGymId($customerId);
        $gym = Gym::query()->find($gymId);
        if (! $gym) {
            throw new RuntimeException('The Stripe Billing customer is not recognised.');
        }

        return $this->context->run($gym, function () use ($event, $rawPayload, $customerId): array {
            $record = SaasBillingWebhookEvent::query()->firstOrCreate(
                ['provider_event_id' => (string) $event['id']],
                [
                    'provider_customer_id' => $customerId,
                    'event_type' => (string) $event['type'],
                    // Store proof of the exact signed bytes without retaining
                    // billing addresses or other provider payload details.
                    'payload_hash' => hash('sha256', $rawPayload),
                    'status' => 'processing',
                ],
            );
            if (! $record->wasRecentlyCreated && $record->status === 'processed') {
                return ['duplicate' => true];
            }

            try {
                DB::transaction(fn () => $this->applyEvent($event, $customerId));
                $record->update(['status' => 'processed', 'processed_at' => now(), 'error' => null]);
            } catch (Throwable $exception) {
                $record->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 2000)]);
                throw $exception;
            }
            return ['duplicate' => false];
        });
    }

    private function applyEvent(array $event, string $customerId): void
    {
        $type = (string) $event['type'];
        $object = $event['data']['object'];
        $objectCustomer = $this->stringId($object['customer'] ?? null);
        if (! hash_equals($customerId, $objectCustomer)) {
            throw new RuntimeException('Stripe Billing event customer mismatch.');
        }
        $customer = PlatformBillingCustomer::query()->where('provider_customer_id', $customerId)->firstOrFail();

        if (in_array($type, ['checkout.session.completed', 'checkout.session.expired'], true)) {
            $this->applyCheckout($type, $object);
            if ($type === 'checkout.session.completed') {
                $subscriptionId = $this->stringId($object['subscription'] ?? null);
                if ($subscriptionId === '') {
                    throw new RuntimeException('Completed subscription checkout has no subscription.');
                }
                $this->syncSubscription($customer, $this->stripe->retrieveSubscription($subscriptionId));
            }
            return;
        }

        if (str_starts_with($type, 'customer.subscription.')) {
            $this->syncSubscription($customer, $object);
            return;
        }

        if (str_starts_with($type, 'invoice.')) {
            $invoice = $this->syncInvoice($customer, $object);
            $subscription = $invoice->subscription;
            if ($subscription && $type === 'invoice.payment_failed') {
                $subscription->update([
                    'status' => SaasSubscriptionStatus::PastDue,
                    'latest_invoice_id' => $invoice->provider_invoice_id,
                    'failure_code' => 'invoice_payment_failed',
                    'failure_message' => 'The latest IronCore subscription invoice could not be collected.',
                ]);
                $this->syncGymStatus(SaasSubscriptionStatus::PastDue, null);
            }
            if ($subscription && $type === 'invoice.paid') {
                $subscription->update([
                    'latest_invoice_id' => $invoice->provider_invoice_id,
                    'failure_code' => null,
                    'failure_message' => null,
                ]);
            }
        }
    }

    private function applyCheckout(string $type, array $object): void
    {
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
        $gymId = trim((string) ($metadata['gym_id'] ?? $object['client_reference_id'] ?? ''));
        if ($gymId === '' || ! hash_equals(mb_strtolower($this->context->id()), mb_strtolower($gymId))) {
            throw new RuntimeException('Stripe subscription checkout metadata does not match the resolved tenant.');
        }

        $session = SubscriptionCheckoutSession::query()
            ->where('provider_session_id', (string) $object['id'])
            ->firstOrFail();
        $session->update($type === 'checkout.session.completed'
            ? ['status' => SubscriptionCheckoutStatus::Completed, 'completed_at' => now()]
            : ['status' => SubscriptionCheckoutStatus::Expired]);
    }

    private function syncSubscription(PlatformBillingCustomer $customer, array $payload): GymSubscription
    {
        $providerSubscriptionId = trim((string) ($payload['id'] ?? ''));
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $gymId = trim((string) ($metadata['gym_id'] ?? ''));
        if ($providerSubscriptionId === '' || $gymId === '' || ! hash_equals(mb_strtolower($this->context->id()), mb_strtolower($gymId))) {
            throw new RuntimeException('Stripe subscription metadata does not match the resolved tenant.');
        }

        $priceId = $this->subscriptionPriceId($payload);
        $price = SaasPlanPrice::query()->with('plan')->where('provider_price_id', $priceId)->first();
        if (! $price) {
            throw new RuntimeException('The Stripe subscription price is not recognised.');
        }
        $status = $this->subscriptionStatus((string) ($payload['status'] ?? ''));
        $values = [
            'billing_customer_id' => $customer->getKey(),
            'saas_plan_id' => $price->saas_plan_id,
            'saas_plan_price_id' => $price->getKey(),
            'provider' => PaymentProvider::Stripe,
            'status' => $status,
            'plan_code_snapshot' => $price->plan->code,
            'plan_name_snapshot' => $price->plan->name,
            'feature_limits_snapshot' => $price->plan->feature_limits,
            'currency' => $price->currency,
            'amount_minor' => $price->amount_minor,
            'billing_interval' => $price->billing_interval,
            'current_period_start' => $this->timestamp($payload['current_period_start'] ?? null),
            'current_period_end' => $this->timestamp($payload['current_period_end'] ?? null),
            'trial_ends_at' => $this->timestamp($payload['trial_end'] ?? null),
            'cancel_at_period_end' => (bool) ($payload['cancel_at_period_end'] ?? false),
            'cancelled_at' => $this->timestamp($payload['canceled_at'] ?? null),
            'ended_at' => $this->timestamp($payload['ended_at'] ?? null),
            'latest_invoice_id' => $this->stringId($payload['latest_invoice'] ?? null) ?: null,
        ];
        if (in_array($status, [SaasSubscriptionStatus::Active, SaasSubscriptionStatus::Trialing], true)) {
            $values['failure_code'] = null;
            $values['failure_message'] = null;
        }

        $subscription = GymSubscription::query()->updateOrCreate(
            ['provider_subscription_id' => $providerSubscriptionId],
            $values,
        );
        $this->syncGymStatus($status, $subscription->trial_ends_at);
        return $subscription;
    }

    private function syncInvoice(PlatformBillingCustomer $customer, array $payload): SaasBillingInvoice
    {
        $providerInvoiceId = trim((string) ($payload['id'] ?? ''));
        $currency = Currency::tryFrom(mb_strtoupper((string) ($payload['currency'] ?? '')));
        if ($providerInvoiceId === '' || ! $currency) {
            throw new RuntimeException('The Stripe Billing invoice is invalid or uses an unsupported currency.');
        }
        $providerSubscriptionId = $this->invoiceSubscriptionId($payload);
        $subscription = $providerSubscriptionId !== ''
            ? GymSubscription::query()->where('provider_subscription_id', $providerSubscriptionId)->first()
            : null;
        $status = SaasInvoiceStatus::tryFrom((string) ($payload['status'] ?? 'draft')) ?? SaasInvoiceStatus::Draft;

        return SaasBillingInvoice::query()->updateOrCreate(
            ['provider_invoice_id' => $providerInvoiceId],
            [
                'billing_customer_id' => $customer->getKey(),
                'gym_subscription_id' => $subscription?->getKey(),
                'number' => $payload['number'] ?? null,
                'status' => $status,
                'currency' => $currency,
                'amount_due_minor' => max(0, (int) ($payload['amount_due'] ?? 0)),
                'amount_paid_minor' => max(0, (int) ($payload['amount_paid'] ?? 0)),
                'amount_remaining_minor' => max(0, (int) ($payload['amount_remaining'] ?? 0)),
                'hosted_invoice_url' => $payload['hosted_invoice_url'] ?? null,
                'invoice_pdf_url' => $payload['invoice_pdf'] ?? null,
                'period_start' => $this->timestamp($payload['period_start'] ?? null),
                'period_end' => $this->timestamp($payload['period_end'] ?? null),
                'due_at' => $this->timestamp($payload['due_date'] ?? null),
                'paid_at' => $this->timestamp($payload['status_transitions']['paid_at'] ?? null),
            ],
        );
    }

    private function syncGymStatus(SaasSubscriptionStatus $status, ?Carbon $trialEndsAt): void
    {
        $gymStatus = match ($status) {
            SaasSubscriptionStatus::Active => GymStatus::Active,
            SaasSubscriptionStatus::Trialing => GymStatus::Trial,
            SaasSubscriptionStatus::Incomplete, SaasSubscriptionStatus::PastDue => GymStatus::PastDue,
            SaasSubscriptionStatus::Unpaid, SaasSubscriptionStatus::Paused,
            SaasSubscriptionStatus::Cancelled, SaasSubscriptionStatus::IncompleteExpired => GymStatus::Suspended,
        };
        // The tenant remains selectable for owners while suspended so they can
        // reach billing recovery; product authorization can narrow elsewhere.
        Gym::query()->whereKey($this->context->id())->update([
            'status' => $gymStatus,
            'trial_ends_at' => $status === SaasSubscriptionStatus::Trialing ? $trialEndsAt : null,
        ]);
    }

    private function resolveGymId(string $providerCustomerId): string
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        if ($pgsql) {
            DB::statement("select set_config('ironcore.current_billing_customer_id', ?, false)", [$providerCustomerId]);
        }
        try {
            $row = DB::table('platform_billing_customers')
                ->where('provider', PaymentProvider::Stripe->value)
                ->where('provider_customer_id', $providerCustomerId)
                ->first(['gym_id']);
        } finally {
            if ($pgsql) {
                DB::statement("select set_config('ironcore.current_billing_customer_id', '', false)");
            }
        }
        if (! $row) {
            throw new RuntimeException('The Stripe Billing customer is not recognised.');
        }
        return (string) $row->gym_id;
    }

    private function subscriptionStatus(string $status): SaasSubscriptionStatus
    {
        return match ($status) {
            'incomplete' => SaasSubscriptionStatus::Incomplete,
            'trialing' => SaasSubscriptionStatus::Trialing,
            'active' => SaasSubscriptionStatus::Active,
            'past_due' => SaasSubscriptionStatus::PastDue,
            'unpaid' => SaasSubscriptionStatus::Unpaid,
            'paused' => SaasSubscriptionStatus::Paused,
            'canceled' => SaasSubscriptionStatus::Cancelled,
            'incomplete_expired' => SaasSubscriptionStatus::IncompleteExpired,
            default => throw new RuntimeException('Unsupported Stripe subscription status.'),
        };
    }

    private function subscriptionPriceId(array $payload): string
    {
        $price = $payload['items']['data'][0]['price'] ?? null;
        return $this->stringId($price);
    }

    private function invoiceSubscriptionId(array $payload): string
    {
        return $this->stringId(
            $payload['subscription']
                ?? $payload['parent']['subscription_details']['subscription']
                ?? null
        );
    }

    private function stringId(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        return is_array($value) ? trim((string) ($value['id'] ?? '')) : '';
    }

    private function timestamp(mixed $value): ?Carbon
    {
        return is_numeric($value) && (int) $value > 0 ? Carbon::createFromTimestampUTC((int) $value) : null;
    }
}
