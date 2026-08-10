<?php

namespace App\Services\Notifications;

use App\Models\NotificationDelivery;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PushNotificationAdapter
{
    public function send(NotificationDelivery $delivery): ?string
    {
        $endpoint = config('services.notifications.push.endpoint');
        $token = config('services.notifications.push.token');
        if (! $endpoint || ! $token || filter_var($endpoint, FILTER_VALIDATE_URL) === false || parse_url($endpoint, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('Push adapter is not configured.');
        }
        $response = Http::withToken($token)->timeout(10)->post($endpoint, [
            'token' => $delivery->destination,
            'title' => (string) ($delivery->variables['subject'] ?? 'IronCore'),
            'body' => (string) ($delivery->variables['body'] ?? ''),
            // Only safe server-authored navigation metadata may be included.
            'data' => $delivery->variables['data'] ?? [],
        ])->throw()->json();
        return isset($response['id']) ? (string) $response['id'] : null;
    }
}
