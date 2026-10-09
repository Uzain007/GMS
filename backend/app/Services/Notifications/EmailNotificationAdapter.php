<?php

namespace App\Services\Notifications;

use App\Mail\BrandedTransactionalMail;
use App\Models\NotificationDelivery;
use Illuminate\Support\Facades\Mail;
use Throwable;

class EmailNotificationAdapter
{
    public function send(NotificationDelivery $delivery): ?string
    {
        $subject = (string) ($delivery->variables['subject'] ?? 'IronCore notification');
        $body = (string) ($delivery->variables['body'] ?? 'You have a new IronCore update.');
        $view = match ($delivery->template_key) {
            'membership_invoice_created' => 'emails.billing.invoice-created',
            'membership_payment_due', 'membership_invoice_overdue' => 'emails.billing.invoice-status',
            'membership_payment_paid' => 'emails.billing.payment-paid',
            'membership_access_restricted', 'membership_access_restored' => 'emails.billing.access-status',
            'workout_plan_assigned' => 'emails.training.workout-plan-assigned',
            default => 'emails.notifications.transactional',
        };
        $member = $delivery->relationLoaded('member')
            ? $delivery->member
            : $delivery->member()->first();
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        $destination = match ($delivery->template_key) {
            'membership_invoice_created', 'membership_payment_due', 'membership_invoice_overdue',
            'membership_payment_paid', 'membership_access_restricted', 'membership_access_restored' => 'member_account',
            'workout_plan_assigned' => 'member_training',
            default => null,
        };
        $actionUrl = $destination
            ? "{$frontendUrl}/#email_destination={$destination}"
            : $frontendUrl;
        try {
            $data = (array) ($delivery->variables['data'] ?? []);
            $billing = str_starts_with($delivery->template_key, 'membership_');
            Mail::to($delivery->destination)->send(new BrandedTransactionalMail(
                $subject,
                $view,
                array_merge($billing ? $this->billingPresentation($delivery->template_key, $data) : [], [
                    'subject' => $subject,
                    'preheader' => $body,
                    'body' => $body,
                    'recipientName' => $member?->first_name,
                    'actionUrl' => $actionUrl,
                ]),
            ));
        } catch (Throwable) {
            throw NotificationProviderException::rejected();
        }

        return null;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function billingPresentation(string $template, array $data): array
    {
        $overdue = $template === 'membership_invoice_overdue';
        $restricted = $template === 'membership_access_restricted';

        return [
            'billingLabel' => 'Membership billing',
            'gymName' => (string) ($data['gym_name'] ?? 'your gym'),
            'invoiceNumber' => (string) ($data['invoice_number'] ?? 'Invoice'),
            'amount' => (string) ($data['amount'] ?? ''),
            'amountPaid' => (string) ($data['amount_paid'] ?? $data['amount'] ?? ''),
            'dueDate' => (string) ($data['due_date'] ?? 'Due on receipt'),
            'paymentDate' => (string) ($data['payment_date'] ?? ''),
            'paymentMethod' => (string) ($data['payment_method'] ?? ''),
            'status' => (string) ($data['status'] ?? ($overdue ? 'Overdue' : 'Payment due')),
            'overdue' => $overdue,
            'restricted' => $restricted,
            'restored' => $template === 'membership_access_restored',
            'accessRestored' => (bool) ($data['access_restored'] ?? false),
            'heading' => match ($template) {
                'membership_invoice_created' => 'Your membership invoice is ready',
                'membership_invoice_overdue' => 'Your membership payment is overdue',
                'membership_payment_due' => 'Your membership payment is due',
                'membership_payment_paid' => 'Your membership payment is confirmed',
                'membership_access_restricted' => 'Your gym access has been restricted',
                'membership_access_restored' => 'Your gym access has been restored',
                default => 'Membership billing update',
            },
            'actionLabel' => in_array($template, ['membership_invoice_created', 'membership_payment_due', 'membership_invoice_overdue'], true)
                ? 'View and pay invoice'
                : 'Open IronCore',
        ];
    }
}
