<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ProductionConfigurationPreflightTest extends TestCase
{
    public function test_safe_resolved_production_configuration_passes_without_connecting_to_providers(): void
    {
        $this->configureSafeProductionShape();

        $exitCode = Artisan::call('ironcore:production-preflight');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('production configuration preflight passed', Artisan::output());
    }

    public function test_unsafe_configuration_fails_without_echoing_configured_values(): void
    {
        $this->configureSafeProductionShape();
        config([
            'app.key' => 'must-not-appear-in-output',
            'app.url' => 'https://api.example.com',
            'services.stripe.webhook_secret' => null,
        ]);

        $exitCode = Artisan::call('ironcore:production-preflight');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('APP_KEY must contain 32 bytes', $output);
        $this->assertStringContainsString('APP_URL must be a public HTTPS origin', $output);
        $this->assertStringContainsString('STRIPE_WEBHOOK_SECRET is required', $output);
        $this->assertStringNotContainsString('must-not-appear-in-output', $output);
    }

    public function test_cookie_and_browser_origin_mismatch_fails_closed(): void
    {
        $this->configureSafeProductionShape();
        config([
            'cors.allowed_origins' => ['https://other-company.co.uk'],
            'sanctum.stateful' => ['other-company.co.uk'],
            'session.domain' => '.other-company.co.uk',
        ]);

        $exitCode = Artisan::call('ironcore:production-preflight');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('CORS_ALLOWED_ORIGINS must include', $output);
        $this->assertStringContainsString('SANCTUM_STATEFUL_DOMAINS must include', $output);
        $this->assertStringContainsString('SESSION_DOMAIN must cover both', $output);
    }

    public function test_optional_notification_adapter_requires_a_complete_https_pair(): void
    {
        $this->configureSafeProductionShape();
        config([
            'services.notifications.sms.endpoint' => 'http://sms.ironcore.co.uk/send',
            'services.notifications.sms.token' => null,
        ]);

        $exitCode = Artisan::call('ironcore:production-preflight');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'NOTIFICATION_SMS_ENDPOINT and NOTIFICATION_SMS_TOKEN must be supplied together',
            Artisan::output(),
        );
    }

    public function test_trusted_proxy_must_be_an_ip_cidr_or_explicit_provider_wildcard(): void
    {
        $this->configureSafeProductionShape();
        config(['app.trusted_proxies' => ['proxy.ironcore.co.uk']]);

        $exitCode = Artisan::call('ironcore:production-preflight');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('TRUSTED_PROXIES must name', Artisan::output());
    }

    private function configureSafeProductionShape(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app.url' => 'https://api.ironcore.co.uk',
            'app.frontend_url' => 'https://app.ironcore.co.uk',
            'app.trusted_proxies' => ['10.0.0.0/8'],
            'cors.allowed_origins' => ['https://app.ironcore.co.uk'],
            'sanctum.stateful' => ['app.ironcore.co.uk'],
            'session.domain' => '.ironcore.co.uk',
            'session.secure' => true,
            'session.encrypt' => true,
            'session.same_site' => 'lax',
            'session.driver' => 'redis',
            'database.default' => 'pgsql',
            'database.connections.pgsql' => [
                'driver' => 'pgsql',
                'url' => null,
                'host' => 'postgres.ironcore.co.uk',
                'database' => 'ironcore',
                'username' => 'ironcore_app',
                'password' => 'deployment-managed-database-value',
                'sslmode' => 'verify-full',
            ],
            'database.redis.default' => [
                'url' => 'rediss://:deployment-managed-redis-value@redis.ironcore.co.uk:6379',
            ],
            'cache.default' => 'redis',
            'queue.default' => 'redis',
            'filesystems.default' => 's3',
            'filesystems.disks.s3' => [
                'driver' => 's3',
                'key' => null,
                'secret' => null,
                'region' => 'eu-west-2',
                'bucket' => 'ironcore-production-private',
                'endpoint' => null,
            ],
            'services.stripe.secret' => 'deployment-managed-stripe-value',
            'services.stripe.webhook_secret' => 'deployment-managed-connect-signing-value',
            'services.stripe.billing_webhook_secret' => 'deployment-managed-billing-signing-value',
            'services.stripe.api_url' => 'https://api.stripe.com',
            'services.stripe.connect_refresh_url' => 'https://app.ironcore.co.uk/payments?stripe=refresh',
            'services.stripe.connect_return_url' => 'https://app.ironcore.co.uk/payments?stripe=return',
            'services.stripe.checkout_success_url' => 'https://app.ironcore.co.uk/payments?checkout=success',
            'services.stripe.checkout_cancel_url' => 'https://app.ironcore.co.uk/payments?checkout=cancelled',
            'services.stripe.billing_checkout_success_url' => 'https://app.ironcore.co.uk/billing?checkout=success',
            'services.stripe.billing_checkout_cancel_url' => 'https://app.ironcore.co.uk/billing?checkout=cancelled',
            'services.stripe.billing_portal_return_url' => 'https://app.ironcore.co.uk/billing',
            'services.notifications.sms.endpoint' => null,
            'services.notifications.sms.token' => null,
            'services.notifications.push.endpoint' => null,
            'services.notifications.push.token' => null,
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => [
                'transport' => 'smtp',
                'url' => null,
                'host' => 'smtp.mail-provider.com',
                'port' => 587,
                'username' => 'ironcore-production',
                'password' => 'deployment-managed-mail-value',
            ],
            'mail.from.address' => 'hello@ironcore.co.uk',
            'logging.default' => 'stderr',
        ]);
    }
}
