<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Password;

class SendPasswordResetLink implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [15, 60, 180];

    public function __construct(public readonly string $email)
    {
    }

    public function handle(): void
    {
        // This is a platform-identity job, so it deliberately establishes no
        // gym context. The public request has already returned the same result
        // for known and unknown addresses before this lookup occurs.
        Password::sendResetLink(['email' => $this->email]);
    }
}
