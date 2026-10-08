<?php

namespace App\Jobs;

use App\Mail\BrandedTransactionalMail;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
        public readonly ?string $recipientName = null,
        public readonly ?string $gymName = null,
    ) {}

    public function handle(): void
    {
        // This is a platform-identity job, so it deliberately establishes no
        // gym context. The public request has already returned the same result
        // for known and unknown addresses before this lookup occurs.
        $result = Password::sendResetLink(['email' => $this->email], function (CanResetPassword $user, string $token): void {
            $isOwnerInvitation = in_array($this->eventType, ['owner_invitation', 'owner_invitation_resend'], true);
            $frontend = rtrim((string) config('app.frontend_url'), '/');
            $resetEmail = rawurlencode((string) $user->getEmailForPasswordReset());
            // Preserve the existing fragment-only reset contract: neither the
            // one-time token nor the email enters an HTTP request or referrer.
            $actionUrl = $frontend.'/#reset_email='.$resetEmail.'&reset_token='.rawurlencode($token);
            $subject = $isOwnerInvitation
                ? ($this->eventType === 'owner_invitation_resend'
                    ? 'Your IronCore account setup link has been renewed'
                    : ($this->gymName ? "You're invited to manage {$this->gymName}" : 'Complete your IronCore account setup'))
                : 'Reset your IronCore password';
            $heading = $isOwnerInvitation
                ? ($this->eventType === 'owner_invitation_resend'
                    ? 'Your account setup link has been renewed'
                    : ($this->gymName ? "You're invited to manage {$this->gymName}" : 'Complete your IronCore account setup'))
                : 'Reset your password';

            Mail::to($this->email)->send(new BrandedTransactionalMail(
                $subject,
                'emails.security.account-access',
                [
                    'subject' => $subject,
                    'preheader' => $isOwnerInvitation
                        ? 'Complete your secure IronCore gym-owner account setup.'
                        : 'Use this secure link to reset your IronCore password.',
                    'heading' => $heading,
                    'recipientName' => $this->recipientName ?: (string) data_get($user, 'name'),
                    'gymName' => $this->gymName,
                    'isOwnerInvitation' => $isOwnerInvitation,
                    'actionUrl' => $actionUrl,
                    'expiresInMinutes' => (int) config('auth.passwords.users.expire', 60),
                ],
            ));
        });
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
