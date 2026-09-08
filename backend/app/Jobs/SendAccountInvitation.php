<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class SendAccountInvitation implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [15, 60, 180];

    public function __construct(
        public readonly string $email,
        public readonly string $gymId,
        public readonly string $gymName,
        public readonly string $token,
        public readonly string $kind,
    ) {}

    public function handle(): void
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $fragment = $this->kind === 'member'
            ? '#activate_gym='.rawurlencode($this->gymId).'&activate_token='.rawurlencode($this->token)
            : '#invite_gym='.rawurlencode($this->gymId).'&invite_token='.rawurlencode($this->token);
        $label = $this->kind === 'member' ? 'member portal' : 'staff portal';
        $url = $frontend.'/'.$fragment;

        // The encrypted queue payload protects the one-time token at rest. Mail
        // uses the same configured production SMTP transport as password reset.
        Mail::raw("You have been invited to the {$label} for {$this->gymName}.\n\nOpen this secure, expiring link:\n{$url}\n\nIf you did not expect this invitation, ignore this email.", function ($message): void {
            $message->to($this->email)->subject('Your IronCore account invitation');
        });
    }
}
