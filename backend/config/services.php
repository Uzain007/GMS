<?php

return [
    'stripe' => [
        // Secrets are environment-only; they are never exposed by an API resource.
        'secret' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'api_url' => env('STRIPE_API_URL', 'https://api.stripe.com'),
        'connect_refresh_url' => env('STRIPE_CONNECT_REFRESH_URL'),
        'connect_return_url' => env('STRIPE_CONNECT_RETURN_URL'),
        'checkout_success_url' => env('STRIPE_CHECKOUT_SUCCESS_URL'),
        'checkout_cancel_url' => env('STRIPE_CHECKOUT_CANCEL_URL'),
        'billing_webhook_secret' => env('STRIPE_BILLING_WEBHOOK_SECRET'),
        'billing_checkout_success_url' => env('STRIPE_BILLING_CHECKOUT_SUCCESS_URL'),
        'billing_checkout_cancel_url' => env('STRIPE_BILLING_CHECKOUT_CANCEL_URL'),
        'billing_portal_return_url' => env('STRIPE_BILLING_PORTAL_RETURN_URL'),
    ],
    'notifications' => [
        // Provider destinations and tokens remain environment-only.
        'sms' => ['endpoint' => env('NOTIFICATION_SMS_ENDPOINT'), 'token' => env('NOTIFICATION_SMS_TOKEN')],
        'push' => ['endpoint' => env('NOTIFICATION_PUSH_ENDPOINT'), 'token' => env('NOTIFICATION_PUSH_TOKEN')],
    ],
];
