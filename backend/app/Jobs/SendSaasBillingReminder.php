<?php

namespace App\Jobs;

use App\Enums\SaasSubscriptionStatus;
use App\Models\Gym;
use App\Models\GymSubscription;
use App\Models\SaasBillingNotification;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendSaasBillingReminder implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(
        public readonly string $gymId,
        public readonly string $notificationId,
    ) {}

    public function handle(TenantContext $context): void
    {
        $gym = Gym::query()->findOrFail($this->gymId);
        $context->run($gym, function () use ($gym): void {
            $notification = DB::transaction(function (): ?SaasBillingNotification {
                $row = SaasBillingNotification::query()->lockForUpdate()->findOrFail($this->notificationId);
                if (! in_array($row->status, ['queued', 'failed'], true) || $row->attempts >= 3) {
                    return null;
                }
                $trialNotice = in_array($row->template_key, ['saas_trial_ending', 'saas_trial_expired'], true);
                if ($trialNotice && GymSubscription::query()
                    ->where('status', SaasSubscriptionStatus::Active->value)->exists()) {
                    $row->update(['status' => 'suppressed', 'failure_code' => 'subscription_active']);
                    return null;
                }
                if (! $trialNotice && $row->invoice()->where('status', 'paid')->exists()) {
                    $row->update(['status' => 'suppressed', 'failure_code' => 'invoice_paid']);
                    return null;
                }
                $row->update(['status' => 'sending', 'attempts' => $row->attempts + 1, 'failure_code' => null]);
                return $row->fresh(['invoice', 'subscription']);
            });
            if (! $notification) {
                return;
            }

            [$subject, $body] = $this->message($gym, $notification);

            try {
                Mail::raw($body, fn ($message) => $message->to($notification->destination)
                    ->subject($subject));
                $notification->update(['status' => 'sent', 'sent_at' => now()]);
            } catch (Throwable $exception) {
                $notification->update(['status' => 'failed', 'failure_code' => 'mail_delivery_failed']);
                throw $exception;
            }
        });
    }

    /** @return array{string,string} */
    private function message(Gym $gym, SaasBillingNotification $notification): array
    {
        $billingUrl = rtrim((string) config('app.frontend_url'), '/');
        if ($notification->template_key === 'saas_trial_ending') {
            $trialEnd = $notification->subscription?->trial_ends_at ?? $gym->trial_ends_at;
            $date = $trialEnd?->copy()->setTimezone($gym->timezone)->format('j M Y') ?? 'tomorrow';

            return [
                'Your IronCore trial ends tomorrow',
                "Your IronCore trial for {$gym->name} ends on {$date}.\n\nSelect a plan and complete payment to keep normal gym operations available.\n\nSign in at {$billingUrl} to review plans and payment options.",
            ];
        }
        if ($notification->template_key === 'saas_trial_expired') {
            return [
                'Action required: your IronCore trial has ended',
                "Your IronCore trial for {$gym->name} has ended. Normal gym operations are now restricted, but you can still sign in, select a plan and complete payment.\n\nSign in at {$billingUrl} to restore access.",
            ];
        }

        $invoice = $notification->invoice;
        if (! $invoice) {
            throw new \LogicException('Invoice reminder is missing its invoice.');
        }
        $localNow = now($gym->timezone);
        $graceEnd = $invoice->grace_ends_at?->copy()->setTimezone($gym->timezone)->startOfDay() ?? $localNow;
        $days = max(0, $localNow->copy()->startOfDay()->diffInDays($graceEnd, false));
        $amount = number_format($invoice->amount_remaining_minor / 100, 2).' '.$invoice->currency->value;
        $warning = $days > 0
            ? "Payment is required within {$days} days to avoid service interruption."
            : 'The grace period has ended and normal gym operations may be restricted.';
        $due = $notification->template_key === 'saas_invoice_due';
        $timing = $due ? 'is due today' : 'is overdue';

        return [
            $due ? 'IronCore subscription payment due today' : 'Action required: IronCore subscription payment overdue',
            "Your IronCore subscription invoice {$invoice->number} {$timing}.\n\nAmount due: {$amount}\n{$warning}\n\nSign in at {$billingUrl} to review billing and payment options.",
        ];
    }
}
