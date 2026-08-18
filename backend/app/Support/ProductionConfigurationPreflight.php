<?php

namespace App\Support;

final class ProductionConfigurationPreflight
{
    /**
     * Return stable, secret-free failures for the resolved production config.
     * Values are deliberately never interpolated because this command runs in
     * deployment logs that may be visible beyond the application operators.
     *
     * @return list<string>
     */
    public function failures(): array
    {
        $failures = [];

        if (config('app.env') !== 'production') {
            $failures[] = 'APP_ENV must equal production.';
        }

        if (config('app.debug') !== false) {
            $failures[] = 'APP_DEBUG must be false.';
        }

        if (! $this->hasValidApplicationKey(config('app.key'))) {
            $failures[] = 'APP_KEY must contain 32 bytes of key material.';
        }

        $apiOrigin = $this->publicHttpsOrigin(config('app.url'));
        if ($apiOrigin === null) {
            $failures[] = 'APP_URL must be a public HTTPS origin without credentials, query or fragment.';
        }

        $frontendOrigin = $this->publicHttpsOrigin(config('app.frontend_url'));
        if ($frontendOrigin === null) {
            $failures[] = 'FRONTEND_URL must be a public HTTPS origin without credentials, query or fragment.';
        }

        $trustedProxies = config('trustedproxy.proxies');
        $hasValidProxyBoundary = $trustedProxies === '*'
            || (is_array($trustedProxies)
                && $trustedProxies !== []
                && ! $this->arrayAny($trustedProxies, fn (mixed $proxy): bool => ! $this->isValidProxyAddress($proxy)));
        if (! $hasValidProxyBoundary) {
            $failures[] = 'TRUSTED_PROXIES must name the production proxy or load-balancer boundary.';
        }

        $corsOrigins = config('cors.allowed_origins');
        if (! is_array($corsOrigins) || $corsOrigins === [] || $this->arrayAny($corsOrigins, fn (mixed $origin): bool => $origin === '*' || $this->publicHttpsOrigin($origin) === null)) {
            $failures[] = 'CORS_ALLOWED_ORIGINS must contain only exact public HTTPS origins.';
        } elseif ($frontendOrigin !== null && ! in_array($frontendOrigin['origin'], array_map(fn (mixed $origin): ?string => $this->publicHttpsOrigin($origin)['origin'] ?? null, $corsOrigins), true)) {
            $failures[] = 'CORS_ALLOWED_ORIGINS must include the exact FRONTEND_URL origin.';
        }

        $statefulDomains = config('sanctum.stateful');
        if (! is_array($statefulDomains) || $statefulDomains === [] || $this->arrayAny($statefulDomains, fn (mixed $domain): bool => ! $this->isPublicAuthority($domain))) {
            $failures[] = 'SANCTUM_STATEFUL_DOMAINS must contain only exact public host names without schemes or wildcards.';
        } elseif ($frontendOrigin !== null && ! in_array($frontendOrigin['authority'], array_map(fn (mixed $domain): string => strtolower(trim((string) $domain)), $statefulDomains), true)) {
            $failures[] = 'SANCTUM_STATEFUL_DOMAINS must include the FRONTEND_URL host.';
        }

        $sessionDomain = is_string(config('session.domain')) ? trim((string) config('session.domain')) : '';
        if ($sessionDomain === '' || ($apiOrigin !== null && ! $this->cookieDomainCovers($apiOrigin['host'], $sessionDomain)) || ($frontendOrigin !== null && ! $this->cookieDomainCovers($frontendOrigin['host'], $sessionDomain))) {
            $failures[] = 'SESSION_DOMAIN must cover both APP_URL and FRONTEND_URL hosts.';
        }

        if (config('session.secure') !== true || config('session.encrypt') !== true || ! in_array(config('session.same_site'), ['lax', 'strict'], true)) {
            $failures[] = 'The session cookie must be secure, encrypted, HttpOnly and SameSite lax or strict.';
        }

        if (config('database.default') !== 'pgsql') {
            $failures[] = 'DB_CONNECTION must equal pgsql.';
        }

        $postgres = config('database.connections.pgsql');
        if (! is_array($postgres) || $this->postgresUsername($postgres) !== 'ironcore_app') {
            $failures[] = 'The PostgreSQL runtime identity must be the non-superuser ironcore_app role.';
        }

        if (! is_array($postgres) || ! $this->postgresHasPassword($postgres)) {
            $failures[] = 'The PostgreSQL runtime connection must include deployment-managed credentials.';
        }

        if (! is_array($postgres) || ! in_array($this->postgresSslMode($postgres), ['require', 'verify-ca', 'verify-full'], true)) {
            $failures[] = 'DB_SSLMODE must require encrypted PostgreSQL transport.';
        }

        foreach (['cache.default' => 'CACHE_STORE', 'session.driver' => 'SESSION_DRIVER', 'queue.default' => 'QUEUE_CONNECTION'] as $key => $environmentName) {
            if (config($key) !== 'redis') {
                $failures[] = "{$environmentName} must equal redis.";
            }
        }

        if (! $this->hasSecureRedisConnection(config('database.redis.default'))) {
            $failures[] = 'REDIS_URL must use rediss and include deployment-managed authentication.';
        }

        if (config('filesystems.default') !== 's3') {
            $failures[] = 'FILESYSTEM_DISK must equal s3.';
        }

        $s3 = config('filesystems.disks.s3');
        if (! is_array($s3) || $this->blank($s3['bucket'] ?? null) || $this->blank($s3['region'] ?? null)) {
            $failures[] = 'AWS_BUCKET and AWS_DEFAULT_REGION are required for private object storage.';
        }

        if (is_array($s3) && $this->blank($s3['key'] ?? null) !== $this->blank($s3['secret'] ?? null)) {
            $failures[] = 'AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY must be supplied together or omitted for an instance role.';
        }

        if (is_array($s3) && ! $this->blank($s3['endpoint'] ?? null) && ! $this->httpsUrl($s3['endpoint'], requirePublicHost: false)) {
            $failures[] = 'AWS_ENDPOINT must use HTTPS when a custom object-storage endpoint is configured.';
        }

        foreach (['services.stripe.secret' => 'STRIPE_SECRET_KEY', 'services.stripe.webhook_secret' => 'STRIPE_WEBHOOK_SECRET', 'services.stripe.billing_webhook_secret' => 'STRIPE_BILLING_WEBHOOK_SECRET'] as $key => $environmentName) {
            if ($this->blank(config($key))) {
                $failures[] = "{$environmentName} is required.";
            }
        }

        foreach ([
            'services.stripe.api_url' => 'STRIPE_API_URL',
            'services.stripe.connect_refresh_url' => 'STRIPE_CONNECT_REFRESH_URL',
            'services.stripe.connect_return_url' => 'STRIPE_CONNECT_RETURN_URL',
            'services.stripe.checkout_success_url' => 'STRIPE_CHECKOUT_SUCCESS_URL',
            'services.stripe.checkout_cancel_url' => 'STRIPE_CHECKOUT_CANCEL_URL',
            'services.stripe.billing_checkout_success_url' => 'STRIPE_BILLING_CHECKOUT_SUCCESS_URL',
            'services.stripe.billing_checkout_cancel_url' => 'STRIPE_BILLING_CHECKOUT_CANCEL_URL',
            'services.stripe.billing_portal_return_url' => 'STRIPE_BILLING_PORTAL_RETURN_URL',
        ] as $key => $environmentName) {
            if (! $this->httpsUrl(config($key), requirePublicHost: true)) {
                $failures[] = "{$environmentName} must be a public HTTPS URL.";
            }
        }

        $stripeCaBundle = config('services.stripe.ca_bundle');
        if (! $this->blank($stripeCaBundle)
            && (! is_file($stripeCaBundle) || ! is_readable($stripeCaBundle))) {
            $failures[] = 'STRIPE_CA_BUNDLE must reference a readable PEM trust bundle when configured.';
        }

        if (config('mail.default') !== 'smtp' || ! $this->hasDeliveringSmtpConfiguration(config('mail.mailers.smtp'))) {
            $failures[] = 'MAIL_MAILER must use authenticated production SMTP rather than a local/log transport.';
        }

        $fromAddress = config('mail.from.address');
        $fromDomain = is_string($fromAddress) && str_contains($fromAddress, '@') ? substr(strrchr($fromAddress, '@'), 1) : '';
        if (! is_string($fromAddress) || filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false || preg_match('/(^|\.)example\.(com|net|org)$/i', $fromDomain) === 1 || preg_match('/\.(test|example|invalid)$/i', $fromDomain) === 1) {
            $failures[] = 'MAIL_FROM_ADDRESS must be a real production sender address.';
        }

        if (! $this->hasProductionLogSink()) {
            $failures[] = 'LOG_CHANNEL must route to stderr, syslog or errorlog for central collection.';
        }

        foreach (['sms' => 'NOTIFICATION_SMS', 'push' => 'NOTIFICATION_PUSH'] as $adapter => $environmentPrefix) {
            $endpoint = config("services.notifications.{$adapter}.endpoint");
            $token = config("services.notifications.{$adapter}.token");
            if ($this->blank($endpoint) && $this->blank($token)) {
                continue;
            }

            if ($this->blank($token) || ! $this->httpsUrl($endpoint, requirePublicHost: true)) {
                $failures[] = "{$environmentPrefix}_ENDPOINT and {$environmentPrefix}_TOKEN must be supplied together with a public HTTPS endpoint.";
            }
        }

        $notificationCaBundle = config('services.notifications.ca_bundle');
        if (! $this->blank($notificationCaBundle)
            && (! is_file($notificationCaBundle) || ! is_readable($notificationCaBundle))) {
            $failures[] = 'NOTIFICATION_CA_BUNDLE must reference a readable PEM trust bundle when configured.';
        }

        return array_values(array_unique($failures));
    }

    private function hasValidApplicationKey(mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }

        if (! str_starts_with($value, 'base64:')) {
            return strlen($value) === 32;
        }

        $decoded = base64_decode(substr($value, 7), true);

        return is_string($decoded) && strlen($decoded) === 32;
    }

    /** @return array{origin: string, authority: string, host: string}|null */
    private function publicHttpsOrigin(mixed $value): ?array
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $parts = parse_url(trim($value));
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        if (($parts['path'] ?? '') !== '' && ($parts['path'] ?? '') !== '/') {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        if (! $this->isPublicHost($host)) {
            return null;
        }

        $authority = $host.(isset($parts['port']) ? ':'.$parts['port'] : '');

        return ['origin' => 'https://'.$authority, 'authority' => $authority, 'host' => $host];
    }

    private function httpsUrl(mixed $value, bool $requirePublicHost): bool
    {
        if (! is_string($value) || trim($value) === '') {
            return false;
        }

        $parts = parse_url(trim($value));
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }

        return ! $requirePublicHost || $this->isPublicHost(strtolower((string) $parts['host']));
    }

    private function isPublicHost(string $host): bool
    {
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || preg_match('/\.(test|example|invalid|local)$/i', $host) === 1 || preg_match('/(^|\.)example\.(com|net|org)$/i', $host) === 1) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/i', $host) === 1 && str_contains($host, '.');
    }

    private function isValidProxyAddress(mixed $value): bool
    {
        if (! is_string($value) || trim($value) === '') {
            return false;
        }

        $proxy = trim($value);
        [$address, $prefix] = array_pad(explode('/', $proxy, 2), 2, null);
        $version = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 4 : (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 6 : null);
        if ($version === null) {
            return false;
        }

        if ($prefix === null) {
            return true;
        }

        return ctype_digit($prefix) && (int) $prefix >= 0 && (int) $prefix <= ($version === 4 ? 32 : 128);
    }

    private function cookieDomainCovers(string $host, string $domain): bool
    {
        $normalized = strtolower(ltrim(trim($domain), '.'));

        return $normalized !== '' && ($host === $normalized || str_ends_with($host, '.'.$normalized));
    }

    private function isPublicAuthority(mixed $value): bool
    {
        if (! is_string($value) || trim($value) === '' || str_contains($value, '://') || str_contains($value, '*')) {
            return false;
        }

        $parts = parse_url('https://'.trim($value));

        return is_array($parts)
            && isset($parts['host'])
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ($parts['path'] ?? '') === ''
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && $this->isPublicHost(strtolower((string) $parts['host']));
    }

    /** @param array<string, mixed> $postgres */
    private function postgresUsername(array $postgres): ?string
    {
        $url = $postgres['url'] ?? null;
        if (is_string($url) && $url !== '') {
            $parts = parse_url($url);
            if (is_array($parts) && isset($parts['user'])) {
                return rawurldecode((string) $parts['user']);
            }
        }

        return is_string($postgres['username'] ?? null) ? $postgres['username'] : null;
    }

    /** @param array<string, mixed> $postgres */
    private function postgresHasPassword(array $postgres): bool
    {
        $url = $postgres['url'] ?? null;
        if (is_string($url) && $url !== '') {
            $parts = parse_url($url);
            if (is_array($parts) && isset($parts['pass'])) {
                return rawurldecode((string) $parts['pass']) !== '';
            }
        }

        return ! $this->blank($postgres['password'] ?? null);
    }

    /** @param array<string, mixed> $postgres */
    private function postgresSslMode(array $postgres): ?string
    {
        $url = $postgres['url'] ?? null;
        if (is_string($url) && $url !== '') {
            $query = parse_url($url, PHP_URL_QUERY);
            if (is_string($query)) {
                parse_str($query, $parameters);
                if (is_string($parameters['sslmode'] ?? null)) {
                    return strtolower($parameters['sslmode']);
                }
            }
        }

        return is_string($postgres['sslmode'] ?? null) ? strtolower($postgres['sslmode']) : null;
    }

    private function hasSecureRedisConnection(mixed $connection): bool
    {
        if (! is_array($connection) || ! is_string($connection['url'] ?? null)) {
            return false;
        }

        $parts = parse_url($connection['url']);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'rediss'
            && isset($parts['host'])
            && isset($parts['pass'])
            && rawurldecode((string) $parts['pass']) !== '';
    }

    private function hasDeliveringSmtpConfiguration(mixed $smtp): bool
    {
        if (! is_array($smtp)) {
            return false;
        }

        if (! $this->blank($smtp['url'] ?? null)) {
            $parts = parse_url((string) $smtp['url']);

            return is_array($parts) && isset($parts['host'], $parts['user'], $parts['pass']) && ! in_array(strtolower((string) $parts['host']), ['localhost', '127.0.0.1', '::1'], true);
        }

        $host = strtolower(trim((string) ($smtp['host'] ?? '')));

        return $host !== ''
            && ! in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            && preg_match('/\.(test|example|invalid|local)$/i', $host) !== 1
            && ! $this->blank($smtp['username'] ?? null)
            && ! $this->blank($smtp['password'] ?? null);
    }

    private function hasProductionLogSink(): bool
    {
        $default = config('logging.default');
        if (in_array($default, ['stderr', 'syslog', 'errorlog'], true)) {
            return true;
        }

        if ($default !== 'stack') {
            return false;
        }

        $channels = config('logging.channels.stack.channels');

        return is_array($channels) && array_intersect($channels, ['stderr', 'syslog', 'errorlog']) !== [];
    }

    private function blank(mixed $value): bool
    {
        return ! is_string($value) || trim($value) === '';
    }

    /** @param array<mixed> $values */
    private function arrayAny(array $values, callable $predicate): bool
    {
        foreach ($values as $value) {
            if ($predicate($value)) {
                return true;
            }
        }

        return false;
    }
}
