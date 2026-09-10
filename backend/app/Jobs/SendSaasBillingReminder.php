<?php

namespace App\Jobs;

use App\Models\Gym;
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
                if ($row->invoice()->where('status', 'paid')->exists()) {
                    $row->update(['status' => 'suppressed', 'failure_code' => 'invoice_paid']);
                    return null;
                }
                $row->update(['status' => 'sending', 'attempts' => $row->attempts + 1, 'failure_code' => null]);
                return $row->fresh('invoice');
            });
            if (! $notification) {
                return;
            }

            $invoice = $notification->invoice;
            $days = max(0, now()->startOfDay()->diffInDays($invoice->grace_ends_at?->startOfDay() ?? now(), false));
            $amount = number_format($invoice->amount_remaining_minor / 100, 2).' '.$invoice->currency->value;
            $warning = $days > 0
                ? "Payment is required within {$days} days to avoid service interruption."
                : 'The grace period has ended and normal gym operations may be restricted.';
            $timing = $notification->template_key === 'saas_invoice_due' ? 'is due today' : 'is overdue';
            $body = "Your IronCore subscription invoice {$invoice->number} {$timing}.\n\nAmount due: {$amount}\n{$warning}\n\nSign in at ".rtrim((string) config('app.frontend_url'), '/')." to review billing and payment options.";

            try {
                Mail::raw($body, fn ($message) => $message->to($notification->destination)
                    ->subject($notification->template_key === 'saas_invoice_due'
                        ? 'IronCore subscription payment due today'
                        : 'Action required: IronCore subscription payment overdue'));
                $notification->update(['status' => 'sent', 'sent_at' => now()]);
            } catch (Throwable $exception) {
                $notification->update(['status' => 'failed', 'failure_code' => 'mail_delivery_failed']);
                throw $exception;
            }
        });
    }
}
