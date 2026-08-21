<?php

namespace Tests\Feature;

use App\Enums\MemberStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Jobs\SendNotificationDelivery;
use App\Models\Gym;
use App\Models\Member;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Notifications\NotificationProviderException;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MilestoneTwentyNotificationProviderRuntimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var(env('IRONCORE_NOTIFICATION_RUNTIME_GATE', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('The notification transport runtime assertions run only in the explicit CI gate.');
        }

        config(['queue.connections.redis.after_commit' => false]);

        // These queues and the provider process are disposable CI resources.
        // Clearing them prevents another feature test from becoming authority
        // for this bounded protocol/tenant-isolation gate.
        Queue::connection('redis')->clear('default');
        Queue::connection('redis')->clear('notifications');
        $this->providerRequest()->post($this->evidenceBaseUrl().'/_reset')->throw();
    }

    public function test_password_recovery_and_tenant_notifications_cross_redis_and_provider_boundaries(): void
    {
        User::factory()->create(['email' => 'runtime-account@example.test']);

        $this->withHeaders($this->browserHeaders())->postJson('/api/v1/auth/forgot-password', [
            'email' => 'runtime-account@example.test',
        ])->assertAccepted();

        $this->drainQueue('default');

        $evidence = $this->evidence();
        $resetMessage = collect($evidence['smtp'])->first(
            fn (array $message): bool => in_array('runtime-account@example.test', $message['recipients'], true),
        );
        $this->assertIsArray($resetMessage);
        $decodedReset = $this->decodedSmtpText($resetMessage);
        $this->assertTrue(
            str_contains($decodedReset, '#reset_email=runtime-account%40example.test'),
            'The SMTP reset message must retain the encoded email in the URL fragment.',
        );
        $this->assertTrue(
            str_contains($decodedReset, 'reset_token='),
            'The SMTP reset message must retain its fragment-only reset token field.',
        );

        [$gym, $member, $actor, $preference] = $this->tenantMember();
        $service = app(NotificationService::class);
        $deliveries = app(TenantContext::class)->run($gym, fn (): array => [
            $service->queue(
                $member,
                $actor,
                NotificationChannel::Email,
                'runtime-member@example.test',
                'runtime_email',
                ['subject' => 'Runtime email', 'body' => 'Email boundary confirmed.'],
                'runtime:email',
                $preference,
            ),
            $service->queue(
                $member,
                $actor,
                NotificationChannel::Sms,
                '+447700900123',
                'runtime_sms',
                ['subject' => 'Runtime SMS', 'body' => 'SMS boundary confirmed.'],
                'runtime:sms',
                $preference,
            ),
            $service->queue(
                $member,
                $actor,
                NotificationChannel::Push,
                'runtime-push-token',
                'runtime_push',
                [
                    'subject' => 'Runtime push',
                    'body' => 'Push boundary confirmed.',
                    'data' => ['view' => 'training'],
                ],
                'runtime:push',
                $preference,
            ),
        ]);

        $this->drainQueue('notifications');

        $evidence = $this->evidence();
        $emailMessage = collect($evidence['smtp'])->first(
            fn (array $message): bool => in_array('runtime-member@example.test', $message['recipients'], true),
        );
        $this->assertIsArray($emailMessage);
        $this->assertTrue(
            str_contains($this->decodedSmtpText($emailMessage), 'Email boundary confirmed.'),
            'The SMTP boundary must preserve the tenant notification body.',
        );
        $this->assertSame([[
            'to' => '+447700900123',
            'message' => 'SMS boundary confirmed.',
        ]], $evidence['sms']);
        $this->assertSame([[
            'token' => 'runtime-push-token',
            'title' => 'Runtime push',
            'body' => 'Push boundary confirmed.',
            'data' => ['view' => 'training'],
        ]], $evidence['push']);

        $states = app(TenantContext::class)->run($gym, fn (): array => collect($deliveries)
            ->map(fn (NotificationDelivery $delivery): NotificationDelivery => $delivery->fresh())
            ->all());
        foreach ($states as $state) {
            $this->assertSame(NotificationDeliveryStatus::Sent, $state->status);
            $this->assertSame(1, $state->attempts);
        }
        $this->assertNull($states[0]->provider_message_id);
        $this->assertSame('ci-sms-1', $states[1]->provider_message_id);
        $this->assertSame('ci-push-1', $states[2]->provider_message_id);
    }

    public function test_notification_job_cannot_resolve_another_tenants_delivery(): void
    {
        [$selectedGym] = $this->tenantMember('SELECTED');
        [$otherGym, $otherMember, $otherActor] = $this->tenantMember('OTHER');

        $otherDelivery = app(TenantContext::class)->run($otherGym, fn (): NotificationDelivery => NotificationDelivery::query()->create([
            'member_id' => $otherMember->id,
            'triggered_by' => $otherActor->id,
            'channel' => NotificationChannel::Email,
            'template_key' => 'cross_tenant_denial',
            'destination' => 'other-tenant@example.test',
            'variables' => ['subject' => 'Must not send', 'body' => 'Must not send', 'data' => []],
            'idempotency_key' => 'runtime:cross-tenant',
            'status' => NotificationDeliveryStatus::Queued,
            'scheduled_at' => now(),
        ]));

        try {
            (new SendNotificationDelivery($selectedGym->id, $otherDelivery->id))
                ->handle(app(TenantContext::class), app(NotificationService::class));
            $this->fail('A notification job resolved a delivery from another tenant.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $state = app(TenantContext::class)->run($otherGym, fn (): NotificationDelivery => $otherDelivery->fresh());
        $this->assertSame(NotificationDeliveryStatus::Queued, $state->status);
        $this->assertSame(0, $state->attempts);
        $this->assertSame([], $this->evidence()['smtp']);
    }

    public function test_provider_failure_is_sanitized_before_queue_failure_evidence(): void
    {
        [$gym, $member, $actor] = $this->tenantMember('REJECT');
        config(['services.notifications.sms.endpoint' => $this->evidenceBaseUrl().'/sms/reject']);

        $delivery = app(TenantContext::class)->run($gym, fn (): NotificationDelivery => NotificationDelivery::query()->create([
            'member_id' => $member->id,
            'triggered_by' => $actor->id,
            'channel' => NotificationChannel::Sms,
            'template_key' => 'provider_rejection',
            'destination' => '+447700900999',
            'variables' => ['subject' => 'Reject', 'body' => 'Reject safely', 'data' => []],
            'idempotency_key' => 'runtime:reject',
            'status' => NotificationDeliveryStatus::Queued,
            'scheduled_at' => now(),
        ]));

        $exception = null;
        try {
            app(TenantContext::class)->run(
                $gym,
                fn () => app(NotificationService::class)->deliver($delivery->id),
            );
        } catch (NotificationProviderException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(NotificationProviderException::class, $exception);
        $this->assertSame('The notification provider rejected the delivery.', $exception->getMessage());
        $this->assertNull($exception->getPrevious());
        foreach ([
            config('services.notifications.runtime_gate.rejection_marker'),
            config('services.notifications.sms.endpoint'),
            config('services.notifications.sms.token'),
            '+447700900999',
        ] as $sensitiveValue) {
            $this->assertStringNotContainsString((string) $sensitiveValue, (string) $exception);
        }

        $state = app(TenantContext::class)->run($gym, fn (): NotificationDelivery => $delivery->fresh());
        $this->assertSame(NotificationDeliveryStatus::Failed, $state->status);
        $this->assertSame('adapter_error', $state->failure_code);
        $this->assertSame('The notification provider rejected the delivery.', $state->failure_message);
        $this->assertSame(1, $this->evidence()['rejections']);
    }

    public function test_disabled_channel_is_suppressed_before_any_provider_request(): void
    {
        [$gym, $member, $actor, $preference] = $this->tenantMember('SUPPRESS');
        app(TenantContext::class)->run($gym, fn () => $preference->update(['sms_enabled' => false]));

        $delivery = app(TenantContext::class)->run($gym, fn (): NotificationDelivery => NotificationDelivery::query()->create([
            'member_id' => $member->id,
            'triggered_by' => $actor->id,
            'channel' => NotificationChannel::Sms,
            'template_key' => 'preference_suppression',
            'destination' => '+447700900555',
            'variables' => ['subject' => 'Suppress', 'body' => 'Must not send', 'data' => []],
            'idempotency_key' => 'runtime:suppress',
            'status' => NotificationDeliveryStatus::Queued,
            'scheduled_at' => now(),
        ]));

        app(TenantContext::class)->run(
            $gym,
            fn () => app(NotificationService::class)->deliver($delivery->id),
        );

        $state = app(TenantContext::class)->run($gym, fn (): NotificationDelivery => $delivery->fresh());
        $this->assertSame(NotificationDeliveryStatus::Suppressed, $state->status);
        $this->assertSame('preference_disabled', $state->failure_code);
        $this->assertSame(0, $state->attempts);
        $this->assertSame([], $this->evidence()['sms']);
    }

    /** @return array{Gym, Member, User, NotificationPreference} */
    private function tenantMember(string $suffix = 'PRIMARY'): array
    {
        $gym = Gym::factory()->create();
        $actor = User::factory()->create();
        [$member, $preference] = app(TenantContext::class)->run($gym, function () use ($suffix): array {
            $member = Member::query()->create([
                'member_number' => 'RUNTIME-'.$suffix,
                'first_name' => 'Runtime',
                'last_name' => $suffix,
                'email' => strtolower($suffix).'@example.test',
                'status' => MemberStatus::Active,
            ]);
            $preference = NotificationPreference::query()->create([
                'member_id' => $member->id,
                'email_enabled' => true,
                'sms_enabled' => true,
                'push_enabled' => true,
                'class_reminders_enabled' => true,
                'workout_reminders_enabled' => true,
                'payment_reminders_enabled' => true,
                'marketing_enabled' => false,
                'timezone' => 'Europe/London',
            ]);

            return [$member, $preference];
        });

        return [$gym, $member, $actor, $preference];
    }

    private function drainQueue(string $queue): void
    {
        $exitCode = Artisan::call('queue:work', [
            'connection' => 'redis',
            '--queue' => $queue,
            '--stop-when-empty' => true,
            '--tries' => 1,
            '--timeout' => 30,
            '--sleep' => 0,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
    }

    /** @return array{smtp: array<int, array<string, mixed>>, sms: array<int, array<string, mixed>>, push: array<int, array<string, mixed>>, rejections: int} */
    private function evidence(): array
    {
        return $this->providerRequest()->get($this->evidenceBaseUrl().'/_evidence')->throw()->json();
    }

    private function providerRequest(): PendingRequest
    {
        return Http::withToken((string) config('services.notifications.runtime_gate.evidence_token'))
            ->withOptions(['verify' => (string) config('services.notifications.ca_bundle')])
            ->timeout(5);
    }

    private function evidenceBaseUrl(): string
    {
        return rtrim((string) config('services.notifications.runtime_gate.evidence_url'), '/');
    }

    /** @param array<string, mixed> $message */
    private function decodedSmtpText(array $message): string
    {
        $parts = $message['text_parts'] ?? null;
        $this->assertIsArray($parts, 'The SMTP boundary must expose decoded MIME text parts.');

        return collect($parts)->map(function (mixed $part): string {
            $this->assertIsArray($part, 'Each SMTP text part must be structured evidence.');
            $this->assertContains($part['media_type'] ?? null, ['text/plain', 'text/html']);
            $this->assertIsString($part['content'] ?? null);

            return $part['content'];
        })->implode("\n");
    }

    /** @return array<string, string> */
    private function browserHeaders(): array
    {
        return ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'];
    }
}
