<?php

$proxies = array_values(array_filter(
    array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))),
    static fn (string $proxy): bool => $proxy !== '',
));

return [
    // Laravel's default HTTP middleware resolves this after configuration is
    // bootstrapped, preserving CLI/package discovery while preventing forged
    // forwarding headers from crossing the reviewed production proxy boundary.
    'proxies' => $proxies === ['*'] ? '*' : $proxies,
];
