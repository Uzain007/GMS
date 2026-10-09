<?php

namespace App\Services;

use App\Enums\NotificationChannel;
use App\Enums\UserRole;
use App\Jobs\SendSaasBillingReminder;
use App\Models\Gym;
use App\Models\GymSubscription;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\Membership;
use App\Models\NotificationPreference;
use App\Models\Payment;
use App\Models\SaasBillingInvoice;
use App\Models\SaasBillingNotification;
use Carbon\CarbonInterface;

class BillingNotificationService
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function memberInvoiceCreated(Invoice $invoice): bool
    {
        return $this->queueMemberInvoice($invoice, 'membership_invoice_created',
            "membership-invoice:{$invoice->id}:created:email", 'Your membership invoice is ready',
            'A new membership invoice is ready to review and pay.');
    }

    public function memberInvoiceDue(Invoice $invoice, bool $overdue, CarbonInterface $localNow): bool
    {
        $template = $overdue ? 'membership_invoice_overdue' : 'membership_payment_due';

        return $this->queueMemberInvoice(
            $invoice, $template,
            "membership-invoice:{$invoice->id}:{$template}:{$localNow->toDateString()}:email",
            $overdue ? 'Action required: your membership invoice is overdue' : 'Your membership payment is due',
            $overdue ? 'Your membership invoice is overdue. Please review the outstanding balance.' : 'Please pay your membership invoice by the due date.',
        );
    }

    public function memberAccessRestricted(Membership $membership, Invoice $invoice): bool
    {
        return $this->queueMemberInvoice(
            $invoice, 'membership_access_restricted',
            "membership:{$membership->id}:invoice:{$invoice->id}:restricted:email",
            'Your gym access has been restricted',
            'Your gym access is restricted while this membership invoice remains outstanding.',
        );
    }

    public function memberPaymentPaid(Payment $payment, bool $accessRestored): void
    {
        $payment->loadMissing(['member', 'membership', 'invoice']);
        if (! $payment->invoice || ! $payment->member?->email) {
            return;
        }
        $gym = Gym::query()->findOrFail($payment->gym_id);
        $data = $this->memberInvoiceData($payment->invoice, $gym) + [
            'payment_id' => $payment->id,
            'amount_paid' => $this->money($payment->amount_minor, $payment->currency->value),
            'payment_date' => $payment->paid_at?->copy()->setTimezone($gym->timezone)->format('j M Y') ?? 'Recorded now',
            'payment_method' => str($payment->method->value)->replace('_', ' ')->headline()->toString(),
            'access_restored' => $accessRestored,
        ];
        $this->queueMember(
            $payment->member, 'membership_payment_paid',
            "membership-payment:{$payment->id}:paid:email",
            'Your membership payment is confirmed',
            'Your membership payment has been successfully recorded.', $data,
        );
        if ($accessRestored && $payment->membership) {
            $this->queueMember(
                $payment->member, 'membership_access_restored',
                "membership:{$payment->membership_id}:payment:{$payment->id}:restored:email",
                'Your gym access has been restored',
                'Your payment has been confirmed and your gym access is restored.', $data,
            );
        }
    }

    public function saasInvoiceCreated(Gym $gym, SaasBillingInvoice $invoice): bool
    {
        return $this->queueSaas($gym, $invoice, $invoice->subscription, 'saas_invoice_created',
            "saas-invoice:{$invoice->id}:created");
    }

    public function saasInvoiceDue(Gym $gym, SaasBillingInvoice $invoice, bool $overdue, CarbonInterface $localNow): bool
    {
        $template = $overdue ? 'saas_invoice_overdue' : 'saas_invoice_due';

        return $this->queueSaas($gym, $invoice, $invoice->subscription, $template,
            implode(':', [$template, $invoice->id, $localNow->toDateString()]));
    }

    public function saasInvoicePaid(Gym $gym, SaasBillingInvoice $invoice, string $paymentKey): bool
    {
        return $this->queueSaas($gym, $invoice, $invoice->subscription, 'saas_invoice_paid',
            "saas-invoice:{$invoice->id}:payment:{$paymentKey}:paid");
    }

    public function saasAccessRestricted(Gym $gym, GymSubscription $subscription, SaasBillingInvoice $invoice): bool
    {
        return $this->queueSaas($gym, $invoice, $subscription, 'saas_account_restricted',
            "saas-subscription:{$subscription->id}:invoice:{$invoice->id}:restricted");
    }

    public function saasAccessRestored(Gym $gym, GymSubscription $subscription, SaasBillingInvoice $invoice, string $paymentKey): bool
    {
        return $this->queueSaas($gym, $invoice, $subscription, 'saas_account_restored',
            "saas-subscription:{$subscription->id}:payment:{$paymentKey}:restored");
    }

    private function queueMemberInvoice(Invoice $invoice, string $template, string $key, string $subject, string $body): bool
    {
        $member = $invoice->relationLoaded('member') ? $invoice->member : $invoice->member()->first();
        if (! $member) {
            return false;
        }
        $gym = Gym::query()->findOrFail($invoice->gym_id);

        return $this->queueMember($member, $template, $key, $subject, $body, $this->memberInvoiceData($invoice, $gym));
    }

    /** @param array<string,mixed> $data */
    private function queueMember(Member $member, string $template, string $key, string $subject, string $body, array $data): bool
    {
        if (! $member->email) {
            return false;
        }
        $preference = NotificationPreference::query()->where('member_id', $member->id)->first();
        $delivery = $this->notifications->queue(
            $member, null, NotificationChannel::Email, $member->email, $template,
            ['subject' => $subject, 'body' => $body, 'data' => $data], $key, $preference,
        );

        return $delivery->wasRecentlyCreated;
    }

    private function queueSaas(Gym $gym, ?SaasBillingInvoice $invoice, ?GymSubscription $subscription, string $template, string $eventKey): bool
    {
        $owner = $gym->users()->wherePivot('role', UserRole::GymOwner->value)
            ->wherePivot('status', 'active')->orderBy('users.id')->first();
        if (! $owner) {
            return false;
        }
        // Hash the server-authored event identity so Stripe identifiers cannot
        // exceed the ledger's bounded unique key while retaining determinism.
        $idempotencyKey = 'billing-email:'.hash('sha256', $eventKey.'|'.$owner->id);
        $notification = SaasBillingNotification::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'gym_subscription_id' => $subscription?->id ?? $invoice?->gym_subscription_id,
                'saas_billing_invoice_id' => $invoice?->id,
                'recipient_user_id' => $owner->id,
                'destination' => $owner->email,
                'template_key' => $template,
                'notification_date' => now($gym->timezone)->toDateString(),
                'status' => 'queued',
            ],
        );
        if ($notification->wasRecentlyCreated) {
            SendSaasBillingReminder::dispatch($gym->id, $notification->id)->onQueue('notifications')->afterCommit();
        }

        return $notification->wasRecentlyCreated;
    }

    /** @return array<string,mixed> */
    private function memberInvoiceData(Invoice $invoice, Gym $gym): array
    {
        return [
            'invoice_id' => $invoice->id,
            'gym_name' => $gym->name,
            'invoice_number' => $invoice->number,
            'amount' => $this->money($invoice->due_amount_minor ?: $invoice->total_amount_minor, $invoice->currency->value),
            'currency' => $invoice->currency->value,
            'due_date' => $invoice->due_at?->copy()->setTimezone($gym->timezone)->format('j M Y') ?? 'Due on receipt',
            'status' => str($invoice->status->value)->replace('_', ' ')->headline()->toString(),
        ];
    }

    private function money(int $minor, string $currency): string
    {
        return number_format($minor / 100, 2).' '.mb_strtoupper($currency);
    }
}
