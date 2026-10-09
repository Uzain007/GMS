<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\GymStatus;
use App\Enums\PaymentProvider;
use App\Enums\SaasSubscriptionStatus;
use App\Enums\UserRole;
use App\Exceptions\SaasBillingReminderDeliveryException;
use App\Jobs\SendSaasBillingReminder;
use App\Models\Gym;
use App\Models\GymSubscription;
use App\Models\PlatformBillingCustomer;
use App\Models\SaasBillingNotification;
use App\Models\SaasPlan;
use App\Models\SaasPlanPrice;
use App\Models\User;
use App\Services\AuditService;
use App\Services\BillingNotificationService;
use App\Services\AutomatedSaasBillingService;
use App\Services\GymSaasStatusService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

class SaasBillingReliabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduler_isolates_tenant_failures_continues_and_reports_failure(): void
    {
        $gymA = Gym::factory()->create(['id' => '00000000-0000-4000-8000-000000000001', 'name' => 'Scheduler tenant A', 'status' => GymStatus::Active]);
        $gymB = Gym::factory()->create(['id' => '00000000-0000-4000-8000-000000000002', 'name' => 'Scheduler tenant B', 'status' => GymStatus::Active]);
        $gymC = Gym::factory()->create(['id' => '00000000-0000-4000-8000-000000000003', 'name' => 'Scheduler tenant C', 'status' => GymStatus::Active]);
        $context = app(TenantContext::class);
        $billing = new class($context, app(AuditService::class), app(GymSaasStatusService::class), app(BillingNotificationService::class), $gymB->id) extends AutomatedSaasBillingService {
            /** @var list<string> */
            public array $processed = [];

            public function __construct(TenantContext $tenant, AuditService $audit, GymSaasStatusService $gymStatus, BillingNotificationService $notifications, private readonly string $failingGymId)
            {
                parent::__construct($tenant, $audit, $gymStatus, $notifications);
            }

            public function processTenant(Gym $gym): array
            {
                $this->processed[] = $gym->id;
                if ($gym->id === $this->failingGymId) {
                    throw new RuntimeException('smtp://provider-user:provider-secret@example.test raw-provider-response');
                }

                return ['invoices_created' => 0, 'reminders_queued' => 0, 'restricted' => 0];
            }
        };
        app()->instance(AutomatedSaasBillingService::class, $billing);
        Log::spy();

        $exit = Artisan::call('ironcore:saas-billing');

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('Processed 3 gyms', Artisan::output());
        $this->assertStringContainsString('1 tenants failed', Artisan::output());
        $this->assertSame([$gymA->id, $gymB->id, $gymC->id], $billing->processed);
        $this->assertFalse($context->hasTenant());
        $this->assertStringNotContainsString('provider-secret', Artisan::output());
        Log::shouldHaveReceived('error')->once()->with(
            'SaaS billing tenant processing failed.',
            Mockery::on(fn (array $context): bool => $context === ['gym_id' => $gymB->id, 'failure_code' => 'tenant_processing_failed']),
        );
    }

    public function test_reminder_failure_preserves_retries_and_exposes_only_sanitized_evidence(): void
    {
        [$gym, $notification, $recipient] = $this->trialReminderFixture();
        $providerEvidence = "SMTP AUTH password=super-secret recipient={$recipient} body={provider-response}";
        Mail::shouldReceive('to')->times(3)->andReturnSelf();
        Mail::shouldReceive('send')->times(3)->andThrow(new RuntimeException($providerEvidence));
        Log::spy();
        $job = new SendSaasBillingReminder($gym->id, $notification->id);
        $this->assertSame(3, $job->tries);
        $this->assertSame([30, 120, 600], $job->backoff);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $job->handle(app(TenantContext::class));
                $this->fail('The provider failure must remain retryable.');
            } catch (SaasBillingReminderDeliveryException $exception) {
                $this->assertSame('SaaS billing reminder delivery failed.', $exception->getMessage());
                $this->assertNull($exception->getPrevious());
                $failedJobEvidence = (string) $exception;
                $this->assertStringNotContainsString('super-secret', $failedJobEvidence);
                $this->assertStringNotContainsString($recipient, $failedJobEvidence);
                $this->assertStringNotContainsString('provider-response', $failedJobEvidence);
            }

            $fresh = app(TenantContext::class)->run($gym, fn () => $notification->fresh());
            $this->assertSame('failed', $fresh->status);
            $this->assertSame('mail_delivery_failed', $fresh->failure_code);
            $this->assertSame($attempt, $fresh->attempts);
            $this->assertFalse(app(TenantContext::class)->hasTenant());
        }

        // The ledger retry cap is authoritative even if a stale queue delivery
        // invokes the same encrypted job again.
        $job->handle(app(TenantContext::class));
        $fresh = app(TenantContext::class)->run($gym, fn () => $notification->fresh());
        $this->assertSame(3, $fresh->attempts);
        $this->assertSame('failed', $fresh->status);
        Log::shouldHaveReceived('warning')->times(3)->with(
            'SaaS billing reminder delivery failed.',
            Mockery::on(fn (array $context): bool => $context === [
                'gym_id' => $gym->id,
                'notification_id' => $notification->id,
                'failure_code' => 'mail_delivery_failed',
            ]),
        );
    }

    /** @return array{Gym,SaasBillingNotification,string} */
    private function trialReminderFixture(): array
    {
        $recipient = 'reliability-owner@example.test';
        $owner = User::factory()->create(['email' => $recipient]);
        $gym = Gym::factory()->create(['status' => GymStatus::Trial, 'base_currency' => Currency::GBP, 'timezone' => 'UTC', 'trial_ends_at' => now()->addDay()]);
        $plan = SaasPlan::query()->create([
            'code' => 'reliability-trial', 'name' => 'Reliability Trial', 'status' => 'active',
            'feature_limits' => ['members' => 100, 'branches' => 1, 'staff' => 5], 'payment_methods' => ['cash'],
        ]);
        $price = SaasPlanPrice::query()->create([
            'saas_plan_id' => $plan->id, 'currency' => Currency::GBP, 'billing_interval' => 'monthly',
            'amount_minor' => 4900, 'trial_days' => 14, 'active' => true,
        ]);

        $notification = app(TenantContext::class)->run($gym, function () use ($gym, $owner, $plan, $price, $recipient): SaasBillingNotification {
            $gym->users()->attach($owner, ['role' => UserRole::GymOwner->value, 'status' => 'active']);
            $customer = PlatformBillingCustomer::query()->create([
                'provider' => PaymentProvider::Manual, 'provider_customer_id' => 'manual_reliability_'.$gym->id,
                'billing_email' => $recipient, 'billing_name' => $gym->name, 'country_code' => 'GB', 'default_currency' => Currency::GBP,
            ]);
            $subscription = GymSubscription::query()->create([
                'billing_customer_id' => $customer->id, 'saas_plan_id' => $plan->id, 'saas_plan_price_id' => $price->id,
                'provider' => PaymentProvider::Manual, 'provider_subscription_id' => GymSubscription::onboardingProviderId($gym->id),
                'status' => SaasSubscriptionStatus::Trialing, 'plan_code_snapshot' => $plan->code, 'plan_name_snapshot' => $plan->name,
                'feature_limits_snapshot' => $plan->feature_limits, 'currency' => Currency::GBP, 'amount_minor' => $price->amount_minor,
                'billing_interval' => 'monthly', 'trial_ends_at' => now()->addDay(), 'next_billing_at' => now()->addDay(), 'grace_period_days' => 15,
            ]);

            return SaasBillingNotification::query()->create([
                'gym_subscription_id' => $subscription->id, 'saas_billing_invoice_id' => null, 'recipient_user_id' => $owner->id,
                'destination' => $recipient, 'template_key' => 'saas_trial_ending', 'notification_date' => today(),
                'idempotency_key' => 'reliability:sanitized-mail-failure', 'status' => 'queued',
            ]);
        });

        return [$gym, $notification, $recipient];
    }
}
