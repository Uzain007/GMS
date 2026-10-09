<?php

namespace App\Services;

use App\Enums\GymStatus;
use App\Enums\PaymentProvider;
use App\Enums\SaasInvoiceStatus;
use App\Enums\SaasSubscriptionStatus;
use App\Enums\UserRole;
use App\Jobs\SendSaasBillingReminder;
use App\Models\Gym;
use App\Models\GymSubscription;
use App\Models\SaasBillingInvoice;
use App\Models\SaasBillingNotification;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AutomatedSaasBillingService
{
    private const INVOICE_LEAD_DAYS = 7;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditService $audit,
        private readonly GymSaasStatusService $gymStatus,
        private readonly BillingNotificationService $billingNotifications,
    ) {}

    /** @return array{gyms:int,invoices_created:int,reminders_queued:int,restricted:int,failed:int} */
    public function runAll(): array
    {
        $totals = ['gyms' => 0, 'invoices_created' => 0, 'reminders_queued' => 0, 'restricted' => 0, 'failed' => 0];
        Gym::query()->where('status', '!=', GymStatus::Cancelled->value)->orderBy('id')
            ->each(function (Gym $gym) use (&$totals): void {
                $totals['gyms']++;

                try {
                    // One tenant failure must not block later billing boundaries;
                    // TenantContext still clears the database RLS state in finally.
                    $result = $this->tenant->run($gym, fn (): array => $this->processTenant($gym));
                    foreach (['invoices_created', 'reminders_queued', 'restricted'] as $key) {
                        $totals[$key] += $result[$key];
                    }
                } catch (Throwable) {
                    $totals['failed']++;
                    Log::error('SaaS billing tenant processing failed.', [
                        'gym_id' => $gym->getKey(),
                        'failure_code' => 'tenant_processing_failed',
                    ]);
                }
            });
        return $totals;
    }

    /** @return array{invoices_created:int,reminders_queued:int,restricted:int} */
    public function processTenant(Gym $gym): array
    {
        $result = ['invoices_created' => 0, 'reminders_queued' => 0, 'restricted' => 0];
        $localNow = now($gym->timezone);

        DB::transaction(function () use ($gym, $localNow, &$result): void {
            // A paid active contract always wins over stale legacy trial rows.
            $subscription = GymSubscription::query()
                ->where('status', SaasSubscriptionStatus::Active->value)
                ->latest()->lockForUpdate()->first()
                ?? GymSubscription::query()->current()->latest()->lockForUpdate()->first();
            $trialExpired = $this->processTrialLifecycle($gym, $subscription, $localNow, $result);
            if ($trialExpired) {
                return;
            }
            if (! $subscription) {
                return;
            }
            // Paused contracts do not accrue IronCore-generated invoices,
            // reminders or grace penalties until the provider resumes them.
            if ($subscription->status === SaasSubscriptionStatus::Paused) {
                return;
            }

            $nextBilling = $subscription->next_billing_at
                ?? $subscription->current_period_end
                ?? $subscription->trial_ends_at;
            if ($nextBilling && ! $subscription->next_billing_at) {
                $subscription->update(['next_billing_at' => $nextBilling]);
            }

            // Stripe creates its own recurring invoices; webhook synchronization
            // remains authoritative. IronCore generates manual invoices only.
            if ($subscription->provider === PaymentProvider::Manual && $nextBilling
                && $nextBilling->lte(now()->addDays(self::INVOICE_LEAD_DAYS))) {
                $invoice = $this->ensureManualInvoice($subscription, $nextBilling);
                if ($invoice->wasRecentlyCreated) {
                    $this->audit->record(
                        'saas.invoice.generated',
                        $invoice,
                        null,
                        after: [
                            'subscription_id' => $subscription->id,
                            'number' => $invoice->number,
                            'status' => $invoice->status->value,
                            'amount_due_minor' => $invoice->amount_due_minor,
                            'currency' => $invoice->currency->value,
                            'due_at' => $invoice->due_at?->toIso8601String(),
                            'grace_ends_at' => $invoice->grace_ends_at?->toIso8601String(),
                        ],
                        reason: 'Automated SaaS renewal invoice generation.',
                    );
                    $this->billingNotifications->saasInvoiceCreated($gym, $invoice->loadMissing('subscription'));
                    $result['invoices_created']++;
                }
            }

            $invoices = SaasBillingInvoice::query()
                ->where('gym_subscription_id', $subscription->id)
                ->whereIn('status', [
                    SaasInvoiceStatus::Upcoming->value,
                    SaasInvoiceStatus::Due->value,
                    SaasInvoiceStatus::PastDue->value,
                    SaasInvoiceStatus::Open->value,
                    SaasInvoiceStatus::Uncollectible->value,
                ])->lockForUpdate()->get();

            foreach ($invoices as $invoice) {
                if (! $invoice->due_at || $invoice->due_at->isFuture()) {
                    if ($invoice->status !== SaasInvoiceStatus::Upcoming && $invoice->status !== SaasInvoiceStatus::Open) {
                        $beforeStatus = $invoice->status->value;
                        $invoice->update(['status' => SaasInvoiceStatus::Upcoming]);
                        $this->audit->record('saas.invoice.status_changed', $invoice->fresh(), null,
                            before: ['status' => $beforeStatus], after: ['status' => SaasInvoiceStatus::Upcoming->value],
                            reason: 'Automated SaaS invoice lifecycle.');
                    }
                    continue;
                }

                $pastDue = $invoice->due_at->copy()->setTimezone($gym->timezone)
                    ->isBefore($localNow->copy()->startOfDay());
                $target = $pastDue ? SaasInvoiceStatus::PastDue : SaasInvoiceStatus::Due;
                if ($invoice->status !== $target) {
                    $beforeStatus = $invoice->status->value;
                    $invoice->update(['status' => $target]);
                    $this->audit->record('saas.invoice.status_changed', $invoice->fresh(), null,
                        before: ['status' => $beforeStatus], after: ['status' => $target->value],
                        reason: 'Automated SaaS invoice lifecycle.');
                }

                $overrideActive = $subscription->billing_override_until?->isFuture() === true;
                if ($pastDue && ! $overrideActive) {
                    if ($subscription->status !== SaasSubscriptionStatus::PastDue) {
                        $beforeStatus = $subscription->status->value;
                        $subscription->update(['status' => SaasSubscriptionStatus::PastDue]);
                        $this->gymStatus->synchronize($gym, SaasSubscriptionStatus::PastDue, reason: 'Automated SaaS invoice overdue lifecycle.');
                        $this->audit->record('saas.subscription.grace_started', $subscription->fresh(), null,
                            before: ['status' => $beforeStatus],
                            after: [
                                'status' => SaasSubscriptionStatus::PastDue->value,
                                'invoice_id' => $invoice->id,
                                'grace_ends_at' => $invoice->grace_ends_at?->toIso8601String(),
                            ], reason: 'The SaaS invoice passed its due date.');
                    }
                }
                // Owners are notified on the due date and daily while overdue;
                // the unique tenant key makes repeated scheduler runs harmless.
                if ($this->billingNotifications->saasInvoiceDue($gym, $invoice->loadMissing('subscription'), $pastDue, $localNow)) {
                    $result['reminders_queued']++;
                }

                if ($invoice->grace_ends_at?->copy()->setTimezone($gym->timezone)->isBefore($localNow)
                    && ! $overrideActive && ! $subscription->billing_restricted_at) {
                    $subscription->update(['billing_restricted_at' => now()]);
                    $this->audit->record('saas.subscription.billing_restricted', $subscription->fresh(), null, after: [
                        'invoice_id' => $invoice->id,
                        'grace_ends_at' => $invoice->grace_ends_at?->toIso8601String(),
                    ], reason: 'The 15-day payment grace period expired.');
                    $this->billingNotifications->saasAccessRestricted($gym, $subscription->fresh(), $invoice);
                    $result['restricted']++;
                }
            }
        });

        return $result;
    }

    private function ensureManualInvoice(GymSubscription $subscription, Carbon $nextBilling): SaasBillingInvoice
    {
        $periodStart = $nextBilling->copy();
        $periodEnd = $subscription->billing_interval === 'yearly'
            ? $periodStart->copy()->addYearNoOverflow()
            : $periodStart->copy()->addMonthNoOverflow();
        $providerId = 'ironcore_auto_'.$subscription->id.'_'.$periodStart->format('Ymd');

        return SaasBillingInvoice::query()->firstOrCreate(
            ['provider_invoice_id' => $providerId],
            [
                'billing_customer_id' => $subscription->billing_customer_id,
                'gym_subscription_id' => $subscription->id,
                'number' => 'IC-SAAS-'.Str::upper((string) Str::ulid()),
                'status' => $periodStart->isFuture() ? SaasInvoiceStatus::Upcoming : SaasInvoiceStatus::Due,
                'currency' => $subscription->currency,
                'amount_due_minor' => $subscription->amount_minor,
                'amount_paid_minor' => 0,
                'amount_remaining_minor' => $subscription->amount_minor,
                'available_at' => now(),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'due_at' => $periodStart,
                'grace_ends_at' => $periodStart->copy()->addDays($subscription->grace_period_days),
            ],
        );
    }

    /**
     * @param  array{invoices_created:int,reminders_queued:int,restricted:int}  $result
     */
    private function processTrialLifecycle(
        Gym $gym,
        ?GymSubscription $subscription,
        Carbon $localNow,
        array &$result,
    ): bool {
        // Any settled active contract wins over stale trial metadata. This also
        // suppresses legacy gym dates after successful manual or Stripe payment.
        if (GymSubscription::query()->where('status', SaasSubscriptionStatus::Active->value)->exists()) {
            return false;
        }

        // The subscription is authoritative. The gym registry date is retained
        // only for legacy tenants created before subscription onboarding.
        $trialEndsAt = $subscription?->trial_ends_at ?? $gym->trial_ends_at;
        if (! $trialEndsAt) {
            return false;
        }

        $localTrialEnd = $trialEndsAt->copy()->setTimezone($gym->timezone);
        if ($localNow->copy()->startOfDay()->equalTo($localTrialEnd->copy()->startOfDay()->subDay())
            && $this->queueTrialNotification($gym, $subscription, $trialEndsAt, 'saas_trial_ending', $localNow)) {
            $result['reminders_queued']++;
        }
        if ($localNow->lt($localTrialEnd)) {
            return false;
        }

        if ($this->queueTrialNotification($gym, $subscription, $trialEndsAt, 'saas_trial_expired', $localNow)) {
            $result['reminders_queued']++;
        }

        if ($subscription) {
            $before = [
                'status' => $subscription->status->value,
                'billing_restricted_at' => $subscription->billing_restricted_at?->toIso8601String(),
            ];
            $newRestriction = $subscription->billing_restricted_at === null;
            if ($subscription->status !== SaasSubscriptionStatus::PastDue || $newRestriction) {
                $subscription->update([
                    'status' => SaasSubscriptionStatus::PastDue,
                    'billing_restricted_at' => $subscription->billing_restricted_at ?? now(),
                ]);
                $this->gymStatus->synchronize(
                    $gym,
                    SaasSubscriptionStatus::PastDue,
                    reason: 'The unpaid SaaS trial expired.',
                );
                $this->audit->record(
                    'saas.subscription.trial_expired',
                    $subscription->fresh(),
                    null,
                    before: $before,
                    after: [
                        'status' => SaasSubscriptionStatus::PastDue->value,
                        'billing_restricted_at' => $subscription->fresh()->billing_restricted_at?->toIso8601String(),
                        'trial_ends_at' => $trialEndsAt->toIso8601String(),
                    ],
                    reason: 'The trial ended without an active paid subscription.',
                );
                if ($newRestriction) {
                    $result['restricted']++;
                }
            }
        } elseif ($gym->status !== GymStatus::PastDue) {
            // Legacy trials without a subscription remain recoverable: Past Due
            // permits login and billing while middleware blocks operations.
            $this->gymStatus->synchronize(
                $gym,
                SaasSubscriptionStatus::PastDue,
                trialEndsAt: $trialEndsAt,
                reason: 'The legacy trial ended without an active paid subscription.',
            );
            $result['restricted']++;
        }

        return true;
    }

    private function queueTrialNotification(
        Gym $gym,
        ?GymSubscription $subscription,
        Carbon $trialEndsAt,
        string $template,
        Carbon $localNow,
    ): bool {
        $owner = $gym->users()->wherePivot('role', UserRole::GymOwner->value)
            ->wherePivot('status', 'active')->orderBy('users.id')->first();
        if (! $owner) {
            return false;
        }

        $trialKey = $trialEndsAt->copy()->utc()->format('YmdHis');
        $key = implode(':', [$template, $subscription?->id ?? 'legacy', $trialKey, $owner->id]);
        $notification = SaasBillingNotification::query()->firstOrCreate(
            ['idempotency_key' => $key],
            [
                'gym_subscription_id' => $subscription?->id,
                'saas_billing_invoice_id' => null,
                'recipient_user_id' => $owner->id,
                'destination' => $owner->email,
                'template_key' => $template,
                'notification_date' => $localNow->toDateString(),
                'status' => 'queued',
            ],
        );
        if ($notification->wasRecentlyCreated) {
            SendSaasBillingReminder::dispatch($gym->id, $notification->id)->onQueue('notifications');
        }

        return $notification->wasRecentlyCreated;
    }

}
