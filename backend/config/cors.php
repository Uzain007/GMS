<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_filter(explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:3000')))),
    'allowed_origins_patterns' => [],
    // X-XSRF-TOKEN is required by the credentialed Sanctum SPA flow; X-Gym-ID
    // is still independently authorised by tenant middleware and PostgreSQL.
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'Origin', 'X-Gym-ID', 'X-Requested-With', 'X-XSRF-TOKEN'],
    'exposed_headers' => ['X-Request-ID'],
    'max_age' => 600,
    'supports_credentials' => true,
];
