<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Throwable;

class SendPasswordResetLink implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [15, 60, 180];

    public function __construct(
        public readonly string $email,
        public readonly string $eventType = 'password_reset',
    ) {}

    public function handle(): void
    {
        // This is a platform-identity job, so it deliberately establishes no
        // gym context. The public request has already returned the same result
        // for known and unknown addresses before this lookup occurs.
        $result = Password::sendResetLink(['email' => $this->email]);
        Log::info('Account email delivery completed.', [
            'event_type' => $this->eventType,
            'recipient' => $this->email,
            'occurred_at' => now()->toIso8601String(),
            'delivery_status' => $result === Password::RESET_LINK_SENT ? 'accepted' : 'suppressed',
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Account email delivery exhausted its retries.', [
            'event_type' => $this->eventType,
            'recipient' => $this->email,
            'occurred_at' => now()->toIso8601String(),
            'delivery_status' => 'failed',
            'failure_reason' => 'The configured mail transport did not accept the message.',
        ]);
    }
}