<?php

namespace App\Jobs;

use App\Enums\SaasSubscriptionStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Exceptions\SaasBillingReminderDeliveryException;
use App\Mail\BrandedTransactionalMail;
use App\Models\Gym;
use App\Models\GymSubscription;
use App\Models\SaasBillingNotification;
use App\Models\SaasSubscriptionPayment;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendSaasBillingReminder implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(
        public readonly string $gymId,
        public readonly string $notificationId,
    ) {}

    public function handle(TenantContext $context): void
    {
        $gym = Gym::query()->findOrFail($this->gymId);
        $context->run($gym, function () use ($gym): void {
            $notification = DB::transaction(function (): ?SaasBillingNotification {
                $row = SaasBillingNotification::query()->lockForUpdate()->findOrFail($this->notificationId);
                if (! in_array($row->status, ['queued', 'failed'], true) || $row->attempts >= 3) {
                    return null;
                }
                $trialNotice = in_array($row->template_key, ['saas_trial_ending', 'saas_trial_expired'], true);
                if ($trialNotice && GymSubscription::query()
                    ->where('status', SaasSubscriptionStatus::Active->value)->exists()) {
                    $row->update(['status' => 'suppressed', 'failure_code' => 'subscription_active']);
                    return null;
                }
                $invoiceReminder = in_array($row->template_key, ['saas_invoice_due', 'saas_invoice_overdue'], true);
                if ($invoiceReminder && $row->invoice()->where('status', 'paid')->exists()) {
                    $row->update(['status' => 'suppressed', 'failure_code' => 'invoice_paid']);
                    return null;
                }
                if (in_array($row->template_key, ['saas_invoice_paid', 'saas_account_restored'], true)
                    && ! $row->invoice()->where('status', 'paid')->exists()) {
                    $row->update(['status' => 'suppressed', 'failure_code' => 'invoice_not_paid']);
                    return null;
                }
                if ($row->template_key === 'saas_account_restricted'
                    && ! $row->subscription()->whereNotNull('billing_restricted_at')->exists()) {
                    $row->update(['status' => 'suppressed', 'failure_code' => 'access_already_restored']);
                    return null;
                }
                $row->update(['status' => 'sending', 'attempts' => $row->attempts + 1, 'failure_code' => null]);
                return $row->fresh(['invoice', 'subscription', 'recipient']);
            });
            if (! $notification) {
                return;
            }

            [$subject, $view, $data] = $this->presentation($gym, $notification);

            try {
                Mail::to($notification->destination)->send(new BrandedTransactionalMail(
                    $subject,
                    $view,
                    $data,
                ));
                $notification->update(['status' => 'sent', 'sent_at' => now()]);
            } catch (Throwable) {
                $notification->update(['status' => 'failed', 'failure_code' => 'mail_delivery_failed']);
                // Provider exceptions may contain recipients, response bodies or
                // transport details. Log and persist stable categories only.
                Log::warning('SaaS billing reminder delivery failed.', [
                    'gym_id' => $gym->getKey(),
                    'notification_id' => $notification->getKey(),
                    'failure_code' => 'mail_delivery_failed',
                ]);
                throw SaasBillingReminderDeliveryException::rejected();
            }
        });
    }

    /** @return array{string,string,array<string,mixed>} */
    private function presentation(Gym $gym, SaasBillingNotification $notification): array
    {
        $billingUrl = rtrim((string) config('app.frontend_url'), '/').'/#email_destination=saas_billing';
        $recipientName = $notification->recipient?->name;
        if ($notification->template_key === 'saas_trial_ending') {
            $trialEnd = $notification->subscription?->trial_ends_at ?? $gym->trial_ends_at;
            $date = $trialEnd?->copy()->setTimezone($gym->timezone)->format('j M Y') ?? 'tomorrow';
            $subject = 'Your IronCore trial ends tomorrow';

            return [
                $subject,
                'emails.saas.trial-ending',
                [
                    'subject' => $subject,
                    'preheader' => "Your IronCore trial for {$gym->name} ends on {$date}.",
                    'recipientName' => $recipientName,
                    'gymName' => $gym->name,
                    'trialEndsAt' => $date,
                    'billingUrl' => $billingUrl,
                ],
            ];
        }
        if ($notification->template_key === 'saas_trial_expired') {
            $subject = 'Action required: your IronCore trial has ended';

            return [
                $subject,
                'emails.saas.trial-expired',
                [
                    'subject' => $subject,
                    'preheader' => "Payment is required to restore normal gym operations for {$gym->name}.",
                    'recipientName' => $recipientName,
                    'gymName' => $gym->name,
                    'billingUrl' => $billingUrl,
                ],
            ];
        }

        $invoice = $notification->invoice;
        if (! $invoice) {
            throw new \LogicException('Invoice reminder is missing its invoice.');
        }
        $amountDue = number_format($invoice->amount_due_minor / 100, 2).' '.$invoice->currency->value;
        $dueDate = $invoice->due_at?->copy()->setTimezone($gym->timezone)->format('j M Y') ?? 'Due on receipt';
        if ($notification->template_key === 'saas_invoice_created') {
            $subject = 'Your IronCore subscription invoice is ready';

            return [$subject, 'emails.billing.invoice-created', [
                'subject' => $subject,
                'preheader' => "Invoice {$invoice->number} for {$gym->name} is ready.",
                'recipientName' => $recipientName,
                'billingLabel' => 'IronCore subscription billing',
                'heading' => 'Your subscription invoice is ready',
                'gymName' => $gym->name,
                'invoiceNumber' => $invoice->number,
                'amount' => $amountDue,
                'dueDate' => $dueDate,
                'status' => str($invoice->status->value)->replace('_', ' ')->headline()->toString(),
                'actionUrl' => $billingUrl,
                'actionLabel' => 'Review billing',
            ]];
        }
        if ($notification->template_key === 'saas_invoice_paid') {
            $payment = SaasSubscriptionPayment::query()
                ->where('saas_billing_invoice_id', $invoice->id)
                ->whereIn('status', [PaymentStatus::Paid->value, PaymentStatus::PartiallyRefunded->value, PaymentStatus::Refunded->value])
                ->latest('paid_at')->first();
            $method = $payment?->method->value
                ?? ($notification->subscription?->provider === PaymentProvider::Stripe ? 'stripe' : 'manual');
            $paidAt = $payment?->paid_at ?? $invoice->paid_at;
            $subject = 'Your IronCore subscription payment is confirmed';

            return [$subject, 'emails.billing.payment-paid', [
                'subject' => $subject,
                'preheader' => "Payment for invoice {$invoice->number} has been confirmed.",
                'recipientName' => $recipientName,
                'billingLabel' => 'IronCore subscription billing',
                'heading' => 'Your subscription payment is confirmed',
                'gymName' => $gym->name,
                'invoiceNumber' => $invoice->number,
                'amountPaid' => number_format($invoice->amount_paid_minor / 100, 2).' '.$invoice->currency->value,
                'paymentDate' => $paidAt?->copy()->setTimezone($gym->timezone)->format('j M Y') ?? 'Recorded now',
                'paymentMethod' => str($method)->replace('_', ' ')->headline()->toString(),
                'accessRestored' => false,
                'actionUrl' => $billingUrl,
                'actionLabel' => 'View billing',
            ]];
        }
        if (in_array($notification->template_key, ['saas_account_restricted', 'saas_account_restored'], true)) {
            $restricted = $notification->template_key === 'saas_account_restricted';
            $subject = $restricted
                ? 'Action required: your IronCore account is restricted'
                : 'Your IronCore account access has been restored';

            return [$subject, 'emails.billing.access-status', [
                'subject' => $subject,
                'preheader' => $restricted
                    ? "Payment is required to restore normal operations for {$gym->name}."
                    : "Normal operations for {$gym->name} are restored.",
                'recipientName' => $recipientName,
                'billingLabel' => 'IronCore subscription billing',
                'heading' => $restricted ? 'Your IronCore account is restricted' : 'Your IronCore account is restored',
                'gymName' => $gym->name,
                'invoiceNumber' => $invoice->number,
                'restricted' => $restricted,
                'restored' => ! $restricted,
                'accessNoun' => 'IronCore account access',
                'actionUrl' => $billingUrl,
                'actionLabel' => $restricted ? 'Restore access' : 'View billing',
            ]];
        }

        $localNow = now($gym->timezone);
        $graceEnd = $invoice->grace_ends_at?->copy()->setTimezone($gym->timezone)->startOfDay() ?? $localNow;
        $days = max(0, $localNow->copy()->startOfDay()->diffInDays($graceEnd, false));
        $amount = number_format($invoice->amount_remaining_minor / 100, 2).' '.$invoice->currency->value;
        $warning = $days > 0
            ? "Payment is required within {$days} days to avoid service interruption."
            : 'The grace period has ended and normal gym operations may be restricted.';
        $due = $notification->template_key === 'saas_invoice_due';
        $subject = $due
            ? 'IronCore subscription payment due today'
            : 'Action required: IronCore subscription payment overdue';
        $dueDate = $invoice->due_at?->copy()->setTimezone($gym->timezone)->format('j M Y') ?? 'Due now';

        return [
            $subject,
            'emails.saas.invoice-status',
            [
                'subject' => $subject,
                'preheader' => "Invoice {$invoice->number}: {$amount} is ".($due ? 'due today.' : 'overdue.'),
                'recipientName' => $recipientName,
                'gymName' => $gym->name,
                'invoiceNumber' => $invoice->number,
                'amount' => $amount,
                'dueDate' => $dueDate,
                'warning' => $warning,
                'overdue' => ! $due,
                'billingUrl' => $billingUrl,
            ],
        ];
    }
}
