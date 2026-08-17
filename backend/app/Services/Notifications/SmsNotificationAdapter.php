<?php

namespace App\Services\Notifications;

use App\Models\NotificationDelivery;
use Illuminate\Support\Facades\Http;
use Throwable;

class SmsNotificationAdapter
{
    public function send(NotificationDelivery $delivery): ?string
    {
        $endpoint = config('services.notifications.sms.endpoint');
        $token = config('services.notifications.sms.token');
        if (! $endpoint || ! $token || filter_var($endpoint, FILTER_VALIDATE_URL) === false || parse_url($endpoint, PHP_URL_SCHEME) !== 'https') {
            throw NotificationProviderException::notConfigured('SMS');
        }

        try {
            $request = Http::withToken($token)->timeout(10);
            if ($caBundle = config('services.notifications.ca_bundle')) {
                // A custom CA is optional and must remain verification-enabling;
                // it supports private provider roots and the disposable CI TLS gate.
                $request = $request->withOptions(['verify' => $caBundle]);
            }
            $response = $request->post($endpoint, [
                'to' => $delivery->destination,
                'message' => (string) ($delivery->variables['body'] ?? ''),
            ])->throw()->json();
        } catch (Throwable) {
            throw NotificationProviderException::rejected();
        }

        return isset($response['id']) ? (string) $response['id'] : null;
    }
}
