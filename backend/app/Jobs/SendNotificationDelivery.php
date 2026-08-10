<?php

namespace App\Jobs;

use App\Models\Gym;
use App\Services\NotificationService;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendNotificationDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 30;

    public function __construct(public readonly string $gymId, public readonly string $deliveryId) {}

    public function handle(TenantContext $context, NotificationService $service): void
    {
        $gym = Gym::query()->findOrFail($this->gymId);
        // Long-lived Redis workers bind and clear Eloquent/RLS tenant state for
        // every immutable delivery payload rather than trusting worker history.
        $context->run($gym, fn () => $service->deliver($this->deliveryId));
    }

    public function failed(Throwable $exception): void
    {
        $gym = Gym::query()->find($this->gymId);
        if (! $gym) {
            return;
        }
        app(TenantContext::class)->run($gym, fn () => app(NotificationService::class)
            ->markPermanentlyFailed($this->deliveryId, $exception));
    }
}
