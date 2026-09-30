<?php

namespace App\Jobs;

use App\Models\Gym;
use App\Models\StaffInvitation;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

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
        public readonly ?string $staffInvitationId = null,
        public readonly string $eventType = 'account_invitation',
    ) {}

    public function handle(?TenantContext $tenant = null): void
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

        $this->recordStaffDelivery($tenant ?? app(TenantContext::class), 'sent', null);
        Log::info('Account invitation email accepted by the configured mail transport.', [
            'event_type' => $this->eventType,
            'recipient' => $this->email,
            'occurred_at' => now()->toIso8601String(),
            'delivery_status' => 'accepted',
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $failureReason = 'The invitation email exhausted its delivery retries.';
        $this->recordStaffDelivery(app(TenantContext::class), 'failed', $failureReason);
        Log::warning('Account invitation email delivery exhausted its retries.', [
            'event_type' => $this->eventType,
            'recipient' => $this->email,
            'occurred_at' => now()->toIso8601String(),
            'delivery_status' => 'failed',
            'failure_reason' => $failureReason,
        ]);
    }

    private function recordStaffDelivery(TenantContext $tenant, string $status, ?string $failureReason): void
    {
        if ($this->kind !== 'staff' || ! $this->staffInvitationId) {
            return;
        }

        $gym = Gym::query()->find($this->gymId);
        if (! $gym) {
            return;
        }

        $tenant->run($gym, function () use ($status, $failureReason): void {
            $invitation = StaffInvitation::query()->find($this->staffInvitationId);
            if (! $invitation) {
                return;
            }

            $invitation->update(['metadata' => array_merge($invitation->metadata ?? [], [
                'delivery' => [
                    'event_type' => $this->eventType,
                    'status' => $status,
                    'updated_at' => now()->toIso8601String(),
                    // Keep provider details out of tenant-visible records.
                    'failure_reason' => $failureReason,
                ],
            ])]);
        });
    }
}