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
use Illuminate\Support\Str;

class AutomatedSaasBillingService
{
    private const INVOICE_LEAD_DAYS = 7;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditService $audit,
        private readonly GymSaasStatusService $gymStatus,
    ) {}

    /** @return array{gyms:int,invoices_created:int,reminders_queued:int,restricted:int} */
    public function runAll(): array
    {
        $totals = ['gyms' => 0, 'invoices_created' => 0, 'reminders_queued' => 0, 'restricted' => 0];
        Gym::query()->where('status', '!=', GymStatus::Cancelled->value)->orderBy('id')
            ->each(function (Gym $gym) use (&$totals): void {
                $result = $this->tenant->run($gym, fn (): array => $this->processTenant($gym));
                $totals['gyms']++;
                foreach (['invoices_created', 'reminders_queued', 'restricted'] as $key) {
                    $totals[$key] += $result[$key];
                }
            });
        return $totals;
    }

    /** @return array{invoices_created:int,reminders_queued:int,restricted:int} */
    public function processTenant(Gym $gym): array
    {
        $result = ['invoices_created' => 0, 'reminders_queued' => 0, 'restricted' => 0];

        DB::transaction(function () use ($gym, &$result): void {
            $subscription = GymSubscription::query()->whereIn('status', [
                SaasSubscriptionStatus::Trialing->value,
                SaasSubscriptionStatus::Active->value,
                SaasSubscriptionStatus::PastDue->value,
                SaasSubscriptionStatus::Unpaid->value,
                SaasSubscriptionStatus::Paused->value,
            ])->latest()->lockForUpdate()->first();
            if (! $subscription) {
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

                $pastDue = $invoice->due_at->isBefore(now()->startOfDay());
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
                        $subscription->update(['status' => SaasSubscriptionStatus::PastDue]);
                        $this->gymStatus->synchronize($gym, SaasSubscriptionStatus::PastDue, reason: 'Automated SaaS invoice overdue lifecycle.');
                    }
                }
                // Owners are notified on the due date and daily while overdue;
                // the unique tenant key makes repeated scheduler runs harmless.
                if ($this->queueOwnerReminder($gym, $invoice, $pastDue ? 'saas_invoice_overdue' : 'saas_invoice_due')) {
                    $result['reminders_queued']++;
                }

                if ($invoice->grace_ends_at?->isPast() && ! $overrideActive && ! $subscription->billing_restricted_at) {
                    $subscription->update(['billing_restricted_at' => now()]);
                    $this->audit->record('saas.subscription.billing_restricted', $subscription->fresh(), null, after: [
                        'invoice_id' => $invoice->id,
                        'grace_ends_at' => $invoice->grace_ends_at?->toIso8601String(),
                    ], reason: 'The 15-day payment grace period expired.');
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

    private function queueOwnerReminder(Gym $gym, SaasBillingInvoice $invoice, string $template): bool
    {
        $owner = $gym->users()->wherePivot('role', UserRole::GymOwner->value)
            ->wherePivot('status', 'active')->orderBy('users.id')->first();
        if (! $owner) {
            return false;
        }

        $key = implode(':', [$template, $invoice->id, now()->toDateString(), $owner->id]);
        $notification = SaasBillingNotification::query()->firstOrCreate(
            ['idempotency_key' => $key],
            [
                'saas_billing_invoice_id' => $invoice->id,
                'recipient_user_id' => $owner->id,
                'destination' => $owner->email,
                'template_key' => $template,
                'notification_date' => now()->toDateString(),
                'status' => 'queued',
            ],
        );
        if ($notification->wasRecentlyCreated) {
            SendSaasBillingReminder::dispatch($gym->id, $notification->id)->onQueue('notifications');
        }
        return $notification->wasRecentlyCreated;
    }
}
