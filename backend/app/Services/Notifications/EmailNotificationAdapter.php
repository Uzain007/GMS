<?php

namespace App\Services\Notifications;

use App\Models\NotificationDelivery;
use Illuminate\Support\Facades\Mail;
use Throwable;

class EmailNotificationAdapter
{
    public function send(NotificationDelivery $delivery): ?string
    {
        $subject = (string) ($delivery->variables['subject'] ?? 'IronCore notification');
        $body = (string) ($delivery->variables['body'] ?? 'You have a new IronCore update.');
        try {
            Mail::raw($body, fn ($message) => $message->to($delivery->destination)->subject($subject));
        } catch (Throwable) {
            throw NotificationProviderException::rejected();
        }

        return null;
    }
}
