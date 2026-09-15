<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\GymStatus;
use App\Enums\PaymentProvider;
use App\Enums\SaasInvoiceStatus;
use App\Enums\SaasSubscriptionStatus;
use App\Enums\UserRole;
use App\Jobs\SendSaasBillingReminder;
use App\Models\Gym;
use App\Models\GymSubscription;
use App\Models\PlatformBillingCustomer;
use App\Models\SaasBillingInvoice;
use App\Models\SaasBillingNotification;
use App\Models\SaasPlan;
use App\Models\SaasPlanPrice;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SaasBillingQueueRuntimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var(env('IRONCORE_RUNTIME_GATE', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('The SaaS Redis worker assertions run only in the explicit runtime gate.');
        }

        config(['queue.connections.redis.after_commit' => false]);
        Queue::connection('redis')->clear('notifications');
        Mail::fake();
    }

    public function test_encrypted_saas_reminders_cross_redis_worker_and_stop_after_payment(): void
    {
        [$gym, $pending, $paid] = $this->fixtures();

        SendSaasBillingReminder::dispatch($gym->id, $pending->id)->onQueue('notifications');
        SendSaasBillingReminder::dispatch($gym->id, $paid->id)->onQueue('notifications');
        $this->assertSame(2, Queue::connection('redis')->size('notifications'));

        $exit = Artisan::call('queue:work', [
            'connection' => 'redis',
            '--queue' => 'notifications',
            '--stop-when-empty' => true,
            '--tries' => 1,
            '--timeout' => 30,
            '--sleep' => 0,
        ]);
        $this->assertSame(0, $exit, Artisan::output());

        app(TenantContext::class)->run($gym, function () use ($pending, $paid): void {
            $this->assertSame('sent', $pending->fresh()->status);
            $this->assertSame(1, $pending->fresh()->attempts);
            $this->assertSame('suppressed', $paid->fresh()->status);
            $this->assertSame('invoice_paid', $paid->fresh()->failure_code);
            $this->assertSame(0, $paid->fresh()->attempts);
        });
        $this->assertSame(0, Queue::connection('redis')->size('notifications'));
    }

    /** @return array{Gym,SaasBillingNotification,SaasBillingNotification} */
    private function fixtures(): array
    {
        $owner = User::factory()->create(['email' => 'saas-runtime-owner@example.test']);
        $gym = Gym::factory()->create([
            'base_currency' => Currency::GBP,
            'timezone' => 'Europe/London',
            'status' => GymStatus::Active,
        ]);
        $plan = SaasPlan::query()->create([
            'code' => 'runtime-queue',
            'name' => 'Runtime Queue',
            'status' => 'active',
            'feature_limits' => ['members' => 100, 'branches' => 1, 'staff' => 5, 'advanced_reports' => false, 'priority_support' => false],
            'payment_methods' => ['cash'],
        ]);
        $price = SaasPlanPrice::query()->create([
            'saas_plan_id' => $plan->id,
            'currency' => Currency::GBP,
            'billing_interval' => 'monthly',
            'amount_minor' => 4900,
            'trial_days' => 0,
            'active' => true,
        ]);

        return app(TenantContext::class)->run($gym, function () use ($gym, $owner, $plan, $price): array {
            $gym->users()->attach($owner, ['role' => UserRole::GymOwner->value, 'status' => 'active']);
            $customer = PlatformBillingCustomer::query()->create([
                'provider' => PaymentProvider::Manual,
                'provider_customer_id' => 'manual_runtime_'.$gym->id,
                'billing_email' => $owner->email,
                'billing_name' => $owner->name,
                'country_code' => 'GB',
                'default_currency' => Currency::GBP,
            ]);
            $subscription = GymSubscription::query()->create([
                'billing_customer_id' => $customer->id,
                'saas_plan_id' => $plan->id,
                'saas_plan_price_id' => $price->id,
                'provider' => PaymentProvider::Manual,
                'provider_subscription_id' => 'manual_runtime_subscription_'.$gym->id,
                'status' => SaasSubscriptionStatus::PastDue,
                'plan_code_snapshot' => $plan->code,
                'plan_name_snapshot' => $plan->name,
                'feature_limits_snapshot' => $plan->feature_limits,
                'currency' => Currency::GBP,
                'amount_minor' => 4900,
                'billing_interval' => 'monthly',
                'current_period_start' => now()->subMonth(),
                'current_period_end' => now()->subDay(),
                'next_billing_at' => now()->subDay(),
            ]);
            $invoice = SaasBillingInvoice::query()->create([
                'billing_customer_id' => $customer->id,
                'gym_subscription_id' => $subscription->id,
                'provider_invoice_id' => 'manual_runtime_invoice_'.$gym->id,
                'number' => 'IC-SAAS-RUNTIME-1',
                'status' => SaasInvoiceStatus::PastDue,
                'currency' => Currency::GBP,
                'amount_due_minor' => 4900,
                'amount_paid_minor' => 0,
                'amount_remaining_minor' => 4900,
                'period_start' => now()->subMonth(),
                'period_end' => now()->subDay(),
                'due_at' => now()->subDay(),
                'grace_ends_at' => now()->addDays(14),
            ]);
            $pending = SaasBillingNotification::query()->create([
                'saas_billing_invoice_id' => $invoice->id,
                'recipient_user_id' => $owner->id,
                'destination' => $owner->email,
                'template_key' => 'saas_invoice_overdue',
                'notification_date' => today(),
                'idempotency_key' => 'runtime:saas:pending',
                'status' => 'queued',
            ]);
            $paidInvoice = $invoice->replicate()->fill([
                'provider_invoice_id' => 'manual_runtime_invoice_paid_'.$gym->id,
                'number' => 'IC-SAAS-RUNTIME-2',
                'status' => SaasInvoiceStatus::Paid,
                'amount_paid_minor' => 4900,
                'amount_remaining_minor' => 0,
                'paid_at' => now(),
            ]);
            $paidInvoice->save();
            $paid = SaasBillingNotification::query()->create([
                'saas_billing_invoice_id' => $paidInvoice->id,
                'recipient_user_id' => $owner->id,
                'destination' => $owner->email,
                'template_key' => 'saas_invoice_overdue',
                'notification_date' => today(),
                'idempotency_key' => 'runtime:saas:paid',
                'status' => 'queued',
            ]);

            return [$gym, $pending, $paid];
        });
    }
}
