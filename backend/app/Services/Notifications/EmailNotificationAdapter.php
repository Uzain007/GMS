<?php

namespace App\Services\Notifications;

use App\Models\NotificationDelivery;
use Illuminate\Support\Facades\Mail;

class EmailNotificationAdapter
{
    public function send(NotificationDelivery $delivery): ?string
    {
        $subject = (string) ($delivery->variables['subject'] ?? 'IronCore notification');
        $body = (string) ($delivery->variables['body'] ?? 'You have a new IronCore update.');
        Mail::raw($body, fn ($message) => $message->to($delivery->destination)->subject($subject));
        return null;
    }
}
