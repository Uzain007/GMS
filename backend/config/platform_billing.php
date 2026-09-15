<?php

return [
    // These are IronCore's platform collection details for SaaS subscriptions.
    // They are deliberately separate from each gym's member-payment settings.
    'bank_transfer' => [
        'account_name' => env('SAAS_BANK_ACCOUNT_NAME'),
        'bank_name' => env('SAAS_BANK_NAME'),
        'account_number_or_iban' => env('SAAS_BANK_ACCOUNT_NUMBER_OR_IBAN'),
        'routing_details' => env('SAAS_BANK_ROUTING_DETAILS'),
        'payment_instructions' => env('SAAS_BANK_PAYMENT_INSTRUCTIONS'),
    ],
];
