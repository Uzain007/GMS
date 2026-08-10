<?php

namespace App\Services\Notifications;

use App\Models\NotificationDelivery;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SmsNotificationAdapter
{
    public function send(NotificationDelivery $delivery): ?string
    {
        $endpoint = config('services.notifications.sms.endpoint');
        $token = config('services.notifications.sms.token');
        if (! $endpoint || ! $token || filter_var($endpoint, FILTER_VALIDATE_URL) === false || parse_url($endpoint, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('SMS adapter is not configured.');
        }
        $response = Http::withToken($token)->timeout(10)->post($endpoint, [
            'to' => $delivery->destination,
            'message' => (string) ($delivery->variables['body'] ?? ''),
        ])->throw()->json();
        return isset($response['id']) ? (string) $response['id'] : null;
    }
}
