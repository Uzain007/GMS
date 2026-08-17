<?php

namespace App\Services\Notifications;

use App\Models\NotificationDelivery;
use Illuminate\Support\Facades\Http;
use Throwable;

class PushNotificationAdapter
{
    public function send(NotificationDelivery $delivery): ?string
    {
        $endpoint = config('services.notifications.push.endpoint');
        $token = config('services.notifications.push.token');
        if (! $endpoint || ! $token || filter_var($endpoint, FILTER_VALIDATE_URL) === false || parse_url($endpoint, PHP_URL_SCHEME) !== 'https') {
            throw NotificationProviderException::notConfigured('Push');
        }

        try {
            $request = Http::withToken($token)->timeout(10);
            if ($caBundle = config('services.notifications.ca_bundle')) {
                $request = $request->withOptions(['verify' => $caBundle]);
            }
            $response = $request->post($endpoint, [
                'token' => $delivery->destination,
                'title' => (string) ($delivery->variables['subject'] ?? 'IronCore'),
                'body' => (string) ($delivery->variables['body'] ?? ''),
                // Only safe server-authored navigation metadata may be included.
                'data' => $delivery->variables['data'] ?? [],
            ])->throw()->json();
        } catch (Throwable) {
            throw NotificationProviderException::rejected();
        }

        return isset($response['id']) ? (string) $response['id'] : null;
    }
}
