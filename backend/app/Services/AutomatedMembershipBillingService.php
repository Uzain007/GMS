<?php

namespace App\Services;

use App\Enums\BillingInterval;
use App\Enums\InvoiceStatus;
use App\Enums\MembershipStatus;
use App\Enums\NotificationChannel;
use App\Models\Gym;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\Membership;
use App\Models\NotificationPreference;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AutomatedMembershipBillingService
{
    private const INVOICE_LEAD_DAYS = 7;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
    ) {}

    /** @return array{gyms:int,invoices_created:int,reminders_queued:int,restricted:int} */
    public function runAll(): array
    {
        $totals = ['gyms' => 0, 'invoices_created' => 0, 'reminders_queued' => 0, 'restricted' => 0];
        Gym::query()->whereNotIn('status', ['suspended', 'cancelled'])->orderBy('id')->each(function (Gym $gym) use (&$totals): void {
            $result = $this->tenant->run($gym, fn (): array => $this->processTenant($gym));
            $totals['gyms']++;
            foreach (['invoices_created', 'reminders_queued', 'restricted'] as $key) {
                $totals[$key] += $result[$key];
            }
        });
        return $totals;
    }

    /** @return array{invoices_created:int,reminders_queued:int,restricted:int} */
    public function processTenant(Gym $gym): array
    {
        $result = ['invoices_created' => 0, 'reminders_queued' => 0, 'restricted' => 0];
        $today = CarbonImmutable::now($gym->timezone)->startOfDay();

        Membership::query()->with('member')
            ->where('status', MembershipStatus::Active->value)
            ->where('auto_renew', true)
            ->whereNotNull('next_billing_at')
            ->whereDate('next_billing_at', '<=', $today->addDays(self::INVOICE_LEAD_DAYS)->toDateString())
            ->orderBy('id')->each(function (Membership $membership) use ($today, &$result): void {
                DB::transaction(function () use ($membership, $today, &$result): void {
                    $locked = Membership::query()->with('member')->lockForUpdate()->findOrFail($membership->getKey());
                    $cycleDate = $locked->next_billing_at?->toDateString();
                    if (! $cycleDate || $locked->billing_interval === BillingInterval::OneTime) {
                        return;
                    }

                    // Use a date-aware lookup because SQLite serializes date
                    // casts with a time suffix while PostgreSQL stores DATE.
                    // The locked membership and database unique key remain the
                    // concurrency boundary for repeated scheduler runs.
                    $invoice = Invoice::query()
                        ->where('membership_id', $locked->getKey())
                        ->whereDate('billing_cycle_date', $cycleDate)
                        ->first();
                    $invoiceCreated = $invoice === null;
                    if ($invoiceCreated) {
                        $invoice = Invoice::query()->create([
                            'membership_id' => $locked->getKey(),
                            'billing_cycle_date' => $cycleDate,
                            'member_id' => $locked->member_id,
                            'branch_id' => $locked->branch_id,
                            'created_by' => $locked->created_by,
                            'number' => 'INV-'.Str::upper((string) Str::ulid()),
                            'status' => InvoiceStatus::Open,
                            'currency' => $locked->currency,
                            'subtotal_amount_minor' => $locked->price_amount_minor,
                            'tax_amount_minor' => 0,
                            'total_amount_minor' => $locked->price_amount_minor,
                            'paid_amount_minor' => 0,
                            'due_amount_minor' => $locked->price_amount_minor,
                            'issued_at' => now(),
                            'due_at' => CarbonImmutable::parse($cycleDate, 'UTC')->endOfDay(),
                            'grace_ends_at' => CarbonImmutable::parse($cycleDate, 'UTC')->endOfDay()->addDays($locked->grace_period_days),
                            'notes' => 'Automated membership renewal invoice.',
                            'metadata' => ['source' => 'membership_billing_lifecycle'],
                        ]);
                    }

                    if ($invoiceCreated) {
                        $invoice->items()->create([
                            'description' => 'Membership renewal', 'quantity' => 1,
                            'unit_amount_minor' => $locked->price_amount_minor,
                            'subtotal_amount_minor' => $locked->price_amount_minor,
                            'tax_amount_minor' => 0, 'total_amount_minor' => $locked->price_amount_minor,
                            'metadata' => ['billing_cycle_date' => $cycleDate],
                        ]);
                        $this->audit->record('membership.invoice.generated', $invoice, null, after: [
                            'membership_id' => $locked->getKey(), 'invoice_id' => $invoice->getKey(),
                            'billing_cycle_date' => $cycleDate, 'amount_minor' => $invoice->total_amount_minor,
                            'currency' => $invoice->currency->value, 'grace_ends_at' => $invoice->grace_ends_at?->toIso8601String(),
                        ], reason: 'Automated membership renewal invoice generation.');
                        $result['invoices_created']++;
                    }

                    if ($invoice->status !== InvoiceStatus::Open || $invoice->due_at?->isFuture()) {
                        return;
                    }

                    if ($this->queueReminder($locked->member, $invoice, $today)) {
                        $result['reminders_queued']++;
                    }

                    if ($invoice->grace_ends_at?->isPast() && ! $locked->billing_restricted_at) {
                        $locked->update(['billing_restricted_at' => now()]);
                        $this->audit->record('membership.billing_restricted', $locked->fresh(), null,
                            after: ['invoice_id' => $invoice->getKey(), 'grace_ends_at' => $invoice->grace_ends_at?->toIso8601String()],
                            reason: 'The membership payment grace period expired.');
                        $result['restricted']++;
                    }
                });
            });

        return $result;
    }

    public function markInvoiceSettled(Invoice $invoice): void
    {
        if (! $invoice->membership_id || ! $invoice->billing_cycle_date || $invoice->status !== InvoiceStatus::Paid) {
            return;
        }
        $membership = Membership::query()->lockForUpdate()->findOrFail($invoice->membership_id);
        $before = [
            'billing_restricted_at' => $membership->billing_restricted_at?->toIso8601String(),
            'next_billing_at' => $membership->next_billing_at?->toDateString(),
        ];
        $membership->update([
            'billing_restricted_at' => null,
            'next_billing_at' => $this->nextCycle($membership),
        ]);
        $this->audit->record('membership.invoice.settled', $membership->fresh(), null, $before, [
            'invoice_id' => $invoice->getKey(), 'billing_restricted_at' => null,
            'next_billing_at' => $membership->fresh()->next_billing_at?->toDateString(),
        ], 'Membership invoice paid; access restored and renewal advanced.');
    }

    private function nextCycle(Membership $membership): ?CarbonImmutable
    {
        $from = CarbonImmutable::parse($membership->next_billing_at ?? today());
        return match ($membership->billing_interval) {
            BillingInterval::Weekly => $from->addWeeks($membership->interval_count),
            BillingInterval::Monthly => $from->addMonthsNoOverflow($membership->interval_count),
            BillingInterval::Quarterly => $from->addMonthsNoOverflow(3 * $membership->interval_count),
            BillingInterval::Yearly => $from->addYearsNoOverflow($membership->interval_count),
            BillingInterval::OneTime => null,
        };
    }

    private function queueReminder(Member $member, Invoice $invoice, CarbonImmutable $today): bool
    {
        if (! $member->email) {
            return false;
        }
        $preference = NotificationPreference::query()->where('member_id', $member->getKey())->first();
        if (! ($preference?->payment_reminders_enabled ?? true) || ! ($preference?->email_enabled ?? true)) {
            return false;
        }
        $delivery = $this->notifications->queue(
            $member, null, NotificationChannel::Email, $member->email, 'membership_payment_due',
            ['subject' => 'Your membership payment is due', 'body' => 'Please pay your open membership invoice before the grace period ends.', 'data' => ['invoice_id' => $invoice->getKey()]],
            "membership-invoice:{$invoice->getKey()}:{$today->toDateString()}:email", $preference,
        );
        return $delivery->wasRecentlyCreated;
    }
}
