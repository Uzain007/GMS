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
            'membership_payment_due' => 'emails.membership.payment-due',
            'workout_plan_assigned' => 'emails.training.workout-plan-assigned',
            default => 'emails.notifications.transactional',
        };
        $member = $delivery->relationLoaded('member')
            ? $delivery->member
            : $delivery->member()->first();
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        $destination = match ($delivery->template_key) {
            'membership_payment_due' => 'member_account',
            'workout_plan_assigned' => 'member_training',
            default => null,
        };
        $actionUrl = $destination
            ? "{$frontendUrl}/#email_destination={$destination}"
            : $frontendUrl;
        try {
            Mail::to($delivery->destination)->send(new BrandedTransactionalMail(
                $subject,
                $view,
                [
                    'subject' => $subject,
                    'preheader' => $body,
                    'body' => $body,
                    'recipientName' => $member?->first_name,
                    'actionUrl' => $actionUrl,
                ],
            ));
        } catch (Throwable) {
            throw NotificationProviderException::rejected();
        }

        return null;
    }
}
