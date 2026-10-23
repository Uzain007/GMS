<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SaasInvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Gym;
use App\Models\SaasBillingInvoice;
use App\Services\AuditService;
use App\Services\BillingNotificationService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PlatformSaasReminderController extends Controller
{
    public function preview(TenantContext $tenant): JsonResponse
    {
        return response()->json(['data' => ['eligible' => $this->eligibleCount($tenant)]]);
    }

    public function sendOne(Request $request, string $gym, string $invoice, TenantContext $tenant, BillingNotificationService $notifications, AuditService $audit): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $gymModel = Gym::query()->findOrFail($gym);

        $queued = $tenant->run($gymModel, function () use ($invoice, $gymModel, $request, $notifications, $audit, $data): bool {
            $row = SaasBillingInvoice::query()->findOrFail($invoice);
            $this->assertOutstanding($row);
            $queued = $notifications->manualSaasPaymentReminder($gymModel, $row, $request->user());
            $audit->record('saas.payment_reminder.sent', $row, $request->user(), after: [
                'invoice_id' => $row->id, 'queued' => $queued,
            ], reason: $data['reason'], request: $request);
            return $queued;
        });

        return response()->json(['data' => ['queued' => $queued ? 1 : 0, 'duplicate_suppressed' => $queued ? 0 : 1]]);
    }

    public function sendAll(Request $request, TenantContext $tenant, BillingNotificationService $notifications, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'confirmed' => ['required', 'accepted'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $result = ['eligible' => 0, 'queued' => 0, 'duplicate_suppressed' => 0];

        Gym::query()->orderBy('id')->each(function (Gym $gym) use (&$result, $tenant, $notifications, $audit, $request, $data): void {
            $tenant->run($gym, function () use (&$result, $gym, $notifications, $audit, $request, $data): void {
                SaasBillingInvoice::query()->whereIn('status', [
                    SaasInvoiceStatus::Due->value, SaasInvoiceStatus::PastDue->value, SaasInvoiceStatus::Open->value,
                ])->where('amount_remaining_minor', '>', 0)->orderBy('due_at')->each(function (SaasBillingInvoice $invoice) use (&$result, $gym, $notifications, $audit, $request, $data): void {
                    $result['eligible']++;
                    $queued = $notifications->manualSaasPaymentReminder($gym, $invoice, $request->user());
                    $result[$queued ? 'queued' : 'duplicate_suppressed']++;
                    $audit->record('saas.payment_reminder.sent', $invoice, $request->user(), after: [
                        'invoice_id' => $invoice->id, 'queued' => $queued,
                    ], reason: $data['reason'], request: $request);
                });
            });
        });

        return response()->json(['data' => $result]);
    }

    private function assertOutstanding(SaasBillingInvoice $invoice): void
    {
        if (! in_array($invoice->status, [SaasInvoiceStatus::Due, SaasInvoiceStatus::PastDue, SaasInvoiceStatus::Open], true)
            || $invoice->amount_remaining_minor <= 0) {
            throw ValidationException::withMessages(['invoice' => ['Only an outstanding due or overdue invoice can receive a reminder.']]);
        }
    }

    private function eligibleCount(TenantContext $tenant): int
    {
        $eligible = 0;
        Gym::query()->orderBy('id')->each(function (Gym $gym) use ($tenant, &$eligible): void {
            $hasEligibleInvoice = $tenant->run($gym, fn (): bool => SaasBillingInvoice::query()
                ->whereIn('status', [
                    SaasInvoiceStatus::Due->value,
                    SaasInvoiceStatus::PastDue->value,
                    SaasInvoiceStatus::Open->value,
                ])->where('amount_remaining_minor', '>', 0)->exists());
            $eligible += $hasEligibleInvoice ? 1 : 0;
        });

        return $eligible;
    }
}
