<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\SaasInvoiceStatus;
use App\Enums\SaasPlanStatus;
use App\Enums\SaasSubscriptionStatus;
use App\Enums\SubscriptionCheckoutStatus;
use App\Models\Gym;
use App\Models\GymSubscription;
use App\Models\PlatformBillingCustomer;
use App\Models\SaasBillingInvoice;
use App\Models\SaasPlan;
use App\Models\SaasPlanPrice;
use App\Models\SaasPaymentRefund;
use App\Models\SaasSubscriptionPayment;
use App\Models\SubscriptionCheckoutSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class SaasBillingService
{
    public function __construct(
        private readonly StripePlatformBillingService $stripe,
        private readonly AuditService $audit,
        private readonly GymSaasStatusService $gymStatus,
    ) {}

    public function createPlan(array $data, User $actor, Request $request): SaasPlan
    {
        return DB::transaction(function () use ($data, $actor, $request): SaasPlan {
            $plan = SaasPlan::query()->create([
                'code' => mb_strtolower($data['code']),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'status' => SaasPlanStatus::Active,
                'feature_limits' => $data['feature_limits'],
                'payment_methods' => array_values($data['payment_methods']),
                'sort_order' => $data['sort_order'] ?? 100,
                'provider' => PaymentProvider::Stripe,
                'provider_product_id' => null,
            ]);
            $plan->prices()->create([
                'currency' => $data['currency'],
                'billing_interval' => $data['billing_interval'],
                'amount_minor' => $data['amount_minor'],
                'trial_days' => $data['trial_days'] ?? 0,
                'active' => true,
                'provider' => PaymentProvider::Stripe,
                'provider_price_id' => null,
            ]);
            $this->audit->record('platform.saas_plan.created', $plan, $actor, after: $plan->load('prices')->toArray(), reason: 'Initial SaaS plan publication', request: $request);

            return $plan->fresh('prices');
        });
    }

    public function updatePlan(SaasPlan $plan, array $data, User $actor, Request $request): SaasPlan
    {
        $before = $plan->load('prices')->toArray();

        return DB::transaction(function () use ($plan, $data, $actor, $request, $before): SaasPlan {
            $priceData = $data['price'] ?? null;
            $plan->update(collect($data)->except(['reason', 'price'])->all());
            if ($priceData) {
                $plan->prices()->where('currency', $priceData['currency'])
                    ->where('billing_interval', $priceData['billing_interval'])
                    ->where('active', true)
                    ->update(['active' => false]);
                $price = $plan->prices()->create([
                    ...$priceData,
                    'trial_days' => $priceData['trial_days'] ?? 0,
                    'active' => true,
                    'provider' => PaymentProvider::Stripe,
                    'provider_price_id' => null,
                ]);
                $this->audit->record('platform.saas_price.created', $price, $actor, after: $price->toArray(), reason: $data['reason'], request: $request);
            }
            $fresh = $plan->fresh('prices');
            $this->audit->record('platform.saas_plan.updated', $fresh, $actor, $before, $fresh->toArray(), $data['reason'], $request);

            return $fresh;
        });
    }

    public function addPrice(SaasPlan $plan, array $data, User $actor, Request $request): SaasPlanPrice
    {
        return DB::transaction(function () use ($plan, $data, $actor, $request): SaasPlanPrice {
            // Prices are append-only. The previous catalogue choice becomes
            // inactive but remains attached to historical subscriptions.
            $plan->prices()->where('currency', $data['currency'])
                ->where('billing_interval', $data['billing_interval'])
                ->where('active', true)
                ->update(['active' => false]);
            $price = $plan->prices()->create([
                'currency' => $data['currency'],
                'billing_interval' => $data['billing_interval'],
                'amount_minor' => $data['amount_minor'],
                'trial_days' => $data['trial_days'] ?? 0,
                'active' => true,
                'provider' => PaymentProvider::Stripe,
                'provider_price_id' => null,
            ]);
            $this->audit->record('platform.saas_price.created', $price, $actor, after: $price->toArray(), reason: $data['reason'], request: $request);

            return $price;
        });
    }

    /** @return array{checkout_url: string, idempotency_reused: bool} */
    public function startCheckout(Gym $gym, SaasPlanPrice $price, string $idempotencyKey, User $actor): array
    {
        $price->loadMissing('plan');
        if (! $price->active || $price->plan->status !== SaasPlanStatus::Active) {
            throw ValidationException::withMessages(['saas_plan_price_id' => ['The selected SaaS price is not active.']]);
        }
        if ($price->currency->value !== $gym->base_currency->value) {
            throw ValidationException::withMessages(['saas_plan_price_id' => ['Select a price in the gym base currency.']]);
        }
        if (! in_array(PaymentProvider::Stripe->value, $price->plan->payment_methods ?? [], true)) {
            throw ValidationException::withMessages(['payment_method' => ['This SaaS plan does not accept card payments.']]);
        }
        // Provider configuration is checked only after the gym explicitly
        // selects card checkout; catalogue publication never reaches Stripe.
        $this->stripe->assertCheckoutAvailable();

        $current = GymSubscription::query()->whereIn('status', [
            SaasSubscriptionStatus::Incomplete->value,
            SaasSubscriptionStatus::Trialing->value,
            SaasSubscriptionStatus::Active->value,
            SaasSubscriptionStatus::PastDue->value,
            SaasSubscriptionStatus::Unpaid->value,
            SaasSubscriptionStatus::Paused->value,
        ])->latest()->first();
        if ($current) {
            throw ValidationException::withMessages(['subscription' => ['Use the billing portal to manage the existing subscription.']]);
        }
        if (SaasSubscriptionPayment::query()->where('status', PaymentStatus::Pending->value)->exists()) {
            throw ValidationException::withMessages(['subscription' => ['Review or cancel the pending manual payment before starting card checkout.']]);
        }

        $existing = SubscriptionCheckoutSession::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            $session = $this->stripe->retrieveCheckout($existing->provider_session_id);

            return ['checkout_url' => (string) $session['url'], 'idempotency_reused' => true];
        }

        $open = SubscriptionCheckoutSession::query()
            ->where('status', SubscriptionCheckoutStatus::Open->value)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest()->first();
        if ($open) {
            // Reuse the one active tenant checkout even when a second browser
            // generated a different request key, preventing duplicate contracts.
            $session = $this->stripe->retrieveCheckout($open->provider_session_id);

            return ['checkout_url' => (string) $session['url'], 'idempotency_reused' => true];
        }

        $price = $this->ensureStripePrice($price);
        $customer = $this->customer($gym, $actor);
        $checkout = $this->stripe->createCheckout($gym, $customer, $price, $idempotencyKey);
        $session = SubscriptionCheckoutSession::query()->create([
            'created_by' => $actor->getKey(),
            'saas_plan_price_id' => $price->getKey(),
            'idempotency_key' => $idempotencyKey,
            'provider_session_id' => $checkout['session_id'],
            'status' => SubscriptionCheckoutStatus::Open,
            'expires_at' => $checkout['expires_at'] ? Carbon::createFromTimestampUTC($checkout['expires_at']) : null,
        ]);
        $this->audit->record(
            'saas.subscription_checkout.started',
            $session,
            $actor,
            after: [
                'saas_plan_price_id' => $price->getKey(),
                'provider' => PaymentProvider::Stripe->value,
                'status' => SubscriptionCheckoutStatus::Open->value,
            ],
        );

        return ['checkout_url' => $checkout['checkout_url'], 'idempotency_reused' => false];
    }

    /** @return array{payment: SaasSubscriptionPayment, reused: bool} */
    public function createManualPayment(
        Gym $gym,
        ?SaasPlanPrice $price,
        array $data,
        User $actor,
        Request $request,
        ?UploadedFile $receipt = null,
    ): array {
        $existing = SaasSubscriptionPayment::query()
            ->where('idempotency_key', $data['idempotency_key'])
            ->with('price.plan')
            ->first();
        if ($existing) {
            return ['payment' => $existing, 'reused' => true];
        }

        $invoice = filled($data['saas_billing_invoice_id'] ?? null)
            ? SaasBillingInvoice::query()->with(['subscription.price.plan'])->findOrFail($data['saas_billing_invoice_id'])
            : null;
        if ($invoice) {
            if (in_array($invoice->status, [SaasInvoiceStatus::Paid, SaasInvoiceStatus::Void, SaasInvoiceStatus::Cancelled], true)
                || $invoice->amount_remaining_minor < 1) {
                throw ValidationException::withMessages(['saas_billing_invoice_id' => ['This invoice is not payable.']]);
            }
            $price = $invoice->subscription?->price;
            if (! $price) {
                throw ValidationException::withMessages(['saas_billing_invoice_id' => ['The invoice has no valid subscription price.']]);
            }
        }
        if (! $price) {
            throw ValidationException::withMessages(['saas_plan_price_id' => ['Select a SaaS plan price or renewal invoice.']]);
        }
        $price->loadMissing('plan');
        $this->assertSelectablePrice($gym, $price);
        $method = PaymentMethod::from($data['method']);
        if (! in_array($method->value, $price->plan->payment_methods ?? [], true)) {
            throw ValidationException::withMessages(['method' => ['This payment method is not available for the selected SaaS plan.']]);
        }
        if ($method === PaymentMethod::BankTransfer && ! $receipt) {
            throw ValidationException::withMessages(['receipt' => ['A bank-transfer receipt is required.']]);
        }
        if (! $invoice && $this->currentSubscription()) {
            throw ValidationException::withMessages(['subscription' => ['Use billing management for the existing subscription.']]);
        }
        if (SaasSubscriptionPayment::query()->where('status', PaymentStatus::Pending->value)->exists()) {
            throw ValidationException::withMessages(['subscription' => ['A manual SaaS payment is already awaiting review.']]);
        }

        $stored = null;
        try {
            $payment = DB::transaction(function () use ($price, $invoice, $data, $actor, $request, $method, $receipt, &$stored): SaasSubscriptionPayment {
                $payment = SaasSubscriptionPayment::query()->create([
                    'saas_plan_price_id' => $price->getKey(),
                    'gym_subscription_id' => $invoice?->gym_subscription_id,
                    'saas_billing_invoice_id' => $invoice?->id,
                    'submitted_by' => $actor->getKey(),
                    'method' => $method,
                    'status' => PaymentStatus::Pending,
                    'amount_minor' => $invoice?->amount_remaining_minor ?? $price->amount_minor,
                    'currency' => $invoice?->currency ?? $price->currency,
                    'idempotency_key' => $data['idempotency_key'],
                    'reference' => trim((string) $data['reference']),
                    'payment_date' => $data['payment_date'] ?? null,
                ]);

                if ($receipt) {
                    $stored = $this->storeManualReceipt($payment, $receipt);
                    $payment->update($stored['attributes']);
                }

                $this->audit->record(
                    'saas.subscription_payment.submitted',
                    $payment,
                    $actor,
                    after: [
                        'payment_id' => $payment->getKey(),
                        'saas_plan_price_id' => $price->getKey(),
                        'method' => $method->value,
                        'status' => PaymentStatus::Pending->value,
                        'amount_minor' => $invoice?->amount_remaining_minor ?? $price->amount_minor,
                        'currency' => ($invoice?->currency ?? $price->currency)->value,
                        'saas_billing_invoice_id' => $invoice?->id,
                        'has_receipt' => (bool) $receipt,
                    ],
                    request: $request,
                );

                return $payment->fresh()->load('price.plan');
            });
        } catch (Throwable $exception) {
            if ($stored) {
                Storage::disk($stored['disk'])->delete($stored['path']);
            }
            throw $exception;
        }

        return ['payment' => $payment, 'reused' => false];
    }

    public function reviewManualPayment(
        Gym $gym,
        SaasSubscriptionPayment $payment,
        array $data,
        User $actor,
        Request $request,
    ): SaasSubscriptionPayment {
        return DB::transaction(function () use ($gym, $payment, $data, $actor, $request): SaasSubscriptionPayment {
            $locked = SaasSubscriptionPayment::query()
                ->with(['price.plan', 'submittedBy'])
                ->lockForUpdate()
                ->findOrFail($payment->getKey());
            if ($locked->status !== PaymentStatus::Pending) {
                throw ValidationException::withMessages(['payment' => ['Only a pending SaaS payment can be reviewed.']]);
            }

            $approved = $data['decision'] === 'approve';
            $before = ['status' => $locked->status->value];
            if (! $approved) {
                $locked->update([
                    'status' => PaymentStatus::Rejected,
                    'reviewed_by' => $actor->getKey(),
                    'reviewed_at' => now(),
                    'review_reason' => $data['reason'],
                ]);
            } else {
                $renewal = $locked->invoice && $locked->subscription;
                if (! $renewal && $this->currentSubscription()) {
                    throw ValidationException::withMessages(['subscription' => ['This gym already has a current SaaS subscription.']]);
                }

                $customer = $renewal
                    ? $locked->subscription->customer
                    : $this->manualCustomer($gym, $locked->submittedBy ?? $actor);
                $periodStart = now();
                $periodEnd = $locked->price->billing_interval === 'yearly'
                    ? $periodStart->copy()->addYearNoOverflow()
                    : $periodStart->copy()->addMonthNoOverflow();
                $subscription = $renewal ? $locked->subscription : GymSubscription::query()->create([
                    'billing_customer_id' => $customer->getKey(),
                    'saas_plan_id' => $locked->price->plan->getKey(),
                    'saas_plan_price_id' => $locked->price->getKey(),
                    'provider' => PaymentProvider::Manual,
                    'provider_subscription_id' => 'manual_'.$locked->getKey(),
                    'status' => SaasSubscriptionStatus::Active,
                    'plan_code_snapshot' => $locked->price->plan->code,
                    'plan_name_snapshot' => $locked->price->plan->name,
                    'feature_limits_snapshot' => $locked->price->plan->feature_limits,
                    'currency' => $locked->currency,
                    'amount_minor' => $locked->amount_minor,
                    'billing_interval' => $locked->price->billing_interval,
                    'current_period_start' => $periodStart,
                    'current_period_end' => $periodEnd,
                    'next_billing_at' => $periodEnd,
                ]);
                $providerInvoiceId = 'manual_invoice_'.$locked->getKey();
                $invoice = $renewal ? $locked->invoice : SaasBillingInvoice::query()->create([
                    'billing_customer_id' => $customer->getKey(),
                    'gym_subscription_id' => $subscription->getKey(),
                    'provider_invoice_id' => $providerInvoiceId,
                    'number' => 'IC-MAN-'.Str::upper((string) Str::ulid()),
                    'status' => SaasInvoiceStatus::Paid,
                    'currency' => $locked->currency,
                    'amount_due_minor' => $locked->amount_minor,
                    'amount_paid_minor' => $locked->amount_minor,
                    'amount_remaining_minor' => 0,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'paid_at' => $periodStart,
                ]);
                if ($renewal) {
                    $periodStart = $invoice->period_start ?? $periodStart;
                    $periodEnd = $invoice->period_end ?? $periodEnd;
                    $invoice->update([
                        'status' => SaasInvoiceStatus::Paid,
                        'amount_paid_minor' => $invoice->amount_due_minor,
                        'amount_remaining_minor' => 0,
                        'paid_at' => now(),
                    ]);
                    $subscription->update([
                        'status' => SaasSubscriptionStatus::Active,
                        'current_period_start' => $periodStart,
                        'current_period_end' => $periodEnd,
                        'next_billing_at' => $periodEnd,
                        'billing_restricted_at' => null,
                        'billing_override_until' => null,
                        'billing_override_by' => null,
                        'billing_override_reason' => null,
                        'failure_code' => null,
                        'failure_message' => null,
                        'latest_invoice_id' => $invoice->provider_invoice_id,
                    ]);
                } else {
                    $subscription->update(['latest_invoice_id' => $providerInvoiceId]);
                }
                $locked->update([
                    'gym_subscription_id' => $subscription->getKey(),
                    'saas_billing_invoice_id' => $invoice->getKey(),
                    'status' => PaymentStatus::Paid,
                    'reviewed_by' => $actor->getKey(),
                    'reviewed_at' => now(),
                    'review_reason' => $data['reason'],
                    'paid_at' => $periodStart,
                ]);
                $this->gymStatus->synchronize(
                    $gym,
                    SaasSubscriptionStatus::Active,
                    actor: $actor,
                    reason: 'Approved SaaS '.$locked->method->value.' payment: '.$data['reason'],
                    request: $request,
                );
            }

            $fresh = $locked->fresh()->load('price.plan');
            $this->audit->record(
                $approved ? 'saas.subscription_payment.approved' : 'saas.subscription_payment.rejected',
                $fresh,
                $actor,
                before: $before,
                after: [
                    'status' => $fresh->status->value,
                    'gym_subscription_id' => $fresh->gym_subscription_id,
                    'saas_billing_invoice_id' => $fresh->saas_billing_invoice_id,
                ],
                reason: $data['reason'],
                request: $request,
            );

            return $fresh;
        });
    }

    public function refundManualPayment(
        Gym $gym,
        SaasSubscriptionPayment $payment,
        array $data,
        User $actor,
        Request $request,
    ): SaasSubscriptionPayment {
        return DB::transaction(function () use ($gym, $payment, $data, $actor, $request): SaasSubscriptionPayment {
            $locked = SaasSubscriptionPayment::query()->with(['invoice', 'subscription'])->lockForUpdate()->findOrFail($payment->id);
            if (! in_array($locked->status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true)) {
                throw ValidationException::withMessages(['payment' => ['Only a paid SaaS transaction can be refunded.']]);
            }
            $remaining = $locked->amount_minor - $locked->refunded_amount_minor;
            if ($data['amount_minor'] > $remaining) {
                throw ValidationException::withMessages(['amount_minor' => ['The refund exceeds the unrefunded payment amount.']]);
            }

            $totalRefunded = $locked->refunded_amount_minor + $data['amount_minor'];
            $refund = SaasPaymentRefund::query()->create([
                'saas_subscription_payment_id' => $locked->id,
                'recorded_by' => $actor->id,
                'status' => RefundStatus::Succeeded,
                'amount_minor' => $data['amount_minor'],
                'currency' => $locked->currency,
                'reason' => $data['reason'],
                'refunded_at' => now(),
            ]);
            $locked->update([
                'refunded_amount_minor' => $totalRefunded,
                'status' => $totalRefunded === $locked->amount_minor ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded,
            ]);

            if ($locked->invoice) {
                $paid = max(0, $locked->invoice->amount_paid_minor - $data['amount_minor']);
                $remainingInvoice = max(0, $locked->invoice->amount_due_minor - $paid);
                $locked->invoice->update([
                    'status' => $locked->invoice->due_at?->isPast() ? SaasInvoiceStatus::PastDue : SaasInvoiceStatus::Due,
                    'amount_paid_minor' => $paid,
                    'amount_remaining_minor' => $remainingInvoice,
                    'paid_at' => null,
                ]);
                if ($locked->subscription) {
                    $locked->subscription->update(['status' => SaasSubscriptionStatus::PastDue]);
                    $this->gymStatus->synchronize($gym, SaasSubscriptionStatus::PastDue, actor: $actor, reason: 'SaaS payment refund: '.$data['reason'], request: $request);
                }
            }
            $this->audit->record('saas.subscription_payment.refunded', $refund, $actor, after: [
                'payment_id' => $locked->id,
                'amount_minor' => $data['amount_minor'],
                'total_refunded_minor' => $totalRefunded,
            ], reason: $data['reason'], request: $request);

            return $locked->fresh()->load(['price.plan', 'corrections.correctedBy:id,name', 'refunds.recordedBy:id,name']);
        });
    }

    public function voidInvoice(SaasBillingInvoice $invoice, string $reason, User $actor, Request $request): SaasBillingInvoice
    {
        return DB::transaction(function () use ($invoice, $reason, $actor, $request): SaasBillingInvoice {
            $locked = SaasBillingInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($locked->amount_paid_minor > 0 || $locked->status === SaasInvoiceStatus::Paid) {
                throw ValidationException::withMessages(['invoice' => ['A paid or partially paid invoice cannot be voided; use a refund or correction.']]);
            }
            if (in_array($locked->status, [SaasInvoiceStatus::Void, SaasInvoiceStatus::Cancelled], true)) {
                throw ValidationException::withMessages(['invoice' => ['This invoice is already closed.']]);
            }
            $before = $locked->toArray();
            $locked->update(['status' => SaasInvoiceStatus::Void, 'voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason]);
            $this->audit->record('saas.invoice.voided', $locked->fresh(), $actor, $before, $locked->fresh()->toArray(), $reason, $request);
            return $locked->fresh();
        });
    }

    public function overrideBillingRestriction(Gym $gym, array $data, User $actor, Request $request): GymSubscription
    {
        return DB::transaction(function () use ($gym, $data, $actor, $request): GymSubscription {
            $subscription = $this->currentSubscription();
            if (! $subscription) {
                throw ValidationException::withMessages(['subscription' => ['No current SaaS subscription exists.']]);
            }
            $before = $subscription->toArray();
            $subscription->update([
                'status' => SaasSubscriptionStatus::Active,
                'billing_restricted_at' => null,
                'billing_override_until' => now()->addDays($data['days']),
                'billing_override_by' => $actor->id,
                'billing_override_reason' => $data['reason'],
            ]);
            $this->gymStatus->synchronize($gym, SaasSubscriptionStatus::Active, actor: $actor, reason: 'Temporary billing override: '.$data['reason'], request: $request);
            $this->audit->record('saas.subscription.billing_override', $subscription->fresh(), $actor, $before, $subscription->fresh()->toArray(), $data['reason'], $request);
            return $subscription->fresh('customer');
        });
    }

    /** @return array{portal_url: string} */
    public function createPortal(): array
    {
        $customer = PlatformBillingCustomer::query()
            ->where('provider', PaymentProvider::Stripe->value)
            ->first();
        if (! $customer) {
            throw ValidationException::withMessages(['subscription' => ['Start a subscription before opening the billing portal.']]);
        }

        return $this->stripe->createPortal($customer);
    }

    private function customer(Gym $gym, User $actor): PlatformBillingCustomer
    {
        $existing = PlatformBillingCustomer::query()
            ->where('provider', PaymentProvider::Stripe->value)
            ->first();
        if ($existing) {
            return $existing;
        }

        $provider = $this->stripe->createCustomer($gym, $actor->email, $gym->legal_name ?: $gym->name);

        return PlatformBillingCustomer::query()->firstOrCreate(
            ['provider' => PaymentProvider::Stripe->value],
            [
                'provider_customer_id' => (string) $provider['id'],
                'billing_email' => $actor->email,
                'billing_name' => $gym->legal_name ?: $gym->name,
                'country_code' => $gym->country_code,
                'default_currency' => $gym->base_currency,
            ],
        );
    }

    private function ensureStripePrice(SaasPlanPrice $price): SaasPlanPrice
    {
        $price->loadMissing('plan');
        if (filled($price->provider_price_id)) {
            return $price;
        }

        $priceData = [
            'currency' => $price->currency->value,
            'billing_interval' => $price->billing_interval,
            'amount_minor' => $price->amount_minor,
            'trial_days' => $price->trial_days,
        ];
        if (blank($price->plan->provider_product_id)) {
            $provider = $this->stripe->createProductAndPrice([
                'code' => $price->plan->code,
                'name' => $price->plan->name,
                'description' => $price->plan->description,
            ], $priceData);
            $productId = $provider['product_id'];
            $priceId = $provider['price_id'];
        } else {
            $productId = $price->plan->provider_product_id;
            $priceId = $this->stripe->createPriceForPlan($price->plan, $priceData);
        }

        // Stripe idempotency keys make repeated provider synchronization safe;
        // row locks ensure only the authoritative IDs become catalogue state.
        return DB::transaction(function () use ($price, $productId, $priceId): SaasPlanPrice {
            $plan = SaasPlan::query()->lockForUpdate()->findOrFail($price->saas_plan_id);
            $lockedPrice = SaasPlanPrice::query()->lockForUpdate()->findOrFail($price->getKey());
            if (blank($plan->provider_product_id)) {
                $plan->update([
                    'provider' => PaymentProvider::Stripe,
                    'provider_product_id' => $productId,
                ]);
            }
            if (blank($lockedPrice->provider_price_id)) {
                $lockedPrice->update([
                    'provider' => PaymentProvider::Stripe,
                    'provider_price_id' => $priceId,
                ]);
            }

            return $lockedPrice->fresh('plan');
        });
    }

    private function assertSelectablePrice(Gym $gym, SaasPlanPrice $price): void
    {
        if (! $price->active || $price->plan->status !== SaasPlanStatus::Active) {
            throw ValidationException::withMessages(['saas_plan_price_id' => ['The selected SaaS price is not active.']]);
        }
        if ($price->currency->value !== $gym->base_currency->value) {
            throw ValidationException::withMessages(['saas_plan_price_id' => ['Select a price in the gym base currency.']]);
        }
    }

    private function currentSubscription(): ?GymSubscription
    {
        return GymSubscription::query()->whereIn('status', [
            SaasSubscriptionStatus::Incomplete->value,
            SaasSubscriptionStatus::Trialing->value,
            SaasSubscriptionStatus::Active->value,
            SaasSubscriptionStatus::PastDue->value,
            SaasSubscriptionStatus::Unpaid->value,
            SaasSubscriptionStatus::Paused->value,
        ])->latest()->first();
    }

    private function manualCustomer(Gym $gym, User $billingContact): PlatformBillingCustomer
    {
        return PlatformBillingCustomer::query()->firstOrCreate(
            ['provider' => PaymentProvider::Manual->value],
            [
                'provider_customer_id' => 'manual_'.Str::uuid(),
                'billing_email' => $billingContact->email,
                'billing_name' => $gym->legal_name ?: $gym->name,
                'country_code' => $gym->country_code,
                'default_currency' => $gym->base_currency,
            ],
        );
    }

    /** @return array{disk: string, path: string, attributes: array<string, mixed>} */
    private function storeManualReceipt(SaasSubscriptionPayment $payment, UploadedFile $receipt): array
    {
        $disk = (string) config('filesystems.default');
        $extension = $receipt->guessExtension() ?: 'bin';
        $directory = "gyms/{$payment->gym_id}/saas-billing/{$payment->getKey()}/bank-transfer";
        $path = Storage::disk($disk)->putFileAs(
            $directory,
            $receipt,
            Str::uuid().'.'.$extension,
            ['visibility' => 'private'],
        );
        if (! $path) {
            throw ValidationException::withMessages(['receipt' => ['The SaaS bank-transfer receipt could not be stored.']]);
        }

        return [
            'disk' => $disk,
            'path' => $path,
            'attributes' => [
                'receipt_disk' => $disk,
                'receipt_path' => $path,
                'receipt_original_name' => Str::limit(basename($receipt->getClientOriginalName()), 240, ''),
                'receipt_mime_type' => (string) $receipt->getMimeType(),
                'receipt_size_bytes' => (int) $receipt->getSize(),
                'receipt_sha256' => hash_file('sha256', $receipt->getRealPath()),
            ],
        ];
    }
}
