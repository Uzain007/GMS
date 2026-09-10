<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SaasPlanStatus;
use App\Enums\SaasSubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StartSaasCheckoutRequest;
use App\Http\Requests\CorrectSaasSubscriptionPaymentRequest;
use App\Http\Requests\StoreSaasSubscriptionPaymentRequest;
use App\Http\Requests\ReviewSaasSubscriptionPaymentRequest;
use App\Http\Requests\StoreSaasPaymentRefundRequest;
use App\Http\Requests\VoidSaasInvoiceRequest;
use App\Http\Requests\OverrideSaasBillingRestrictionRequest;
use App\Http\Resources\GymSubscriptionResource;
use App\Http\Resources\SaasBillingInvoiceResource;
use App\Http\Resources\SaasPlanResource;
use App\Http\Resources\SaasSubscriptionPaymentResource;
use App\Models\GymSubscription;
use App\Models\SaasBillingInvoice;
use App\Models\SaasPlan;
use App\Models\SaasPlanPrice;
use App\Models\SaasSubscriptionPayment;
use App\Models\SaasPaymentCorrection;
use App\Services\AuditService;
use App\Support\SimplePdfDocument;
use App\Services\SaasBillingService;
use App\Services\StripePlatformBillingService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Shuchkin\SimpleXLSXGen;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SaasSubscriptionController extends Controller
{
    public function plans(): AnonymousResourceCollection
    {
        // The catalogue is platform-owned; selected-gym authorization is still
        // required before exposing which prices can be purchased by that tenant.
        return SaasPlanResource::collection(
            SaasPlan::query()->where('status', SaasPlanStatus::Active->value)
                ->with(['prices' => fn ($query) => $query->where('active', true)->orderBy('currency')->orderBy('billing_interval')])
                ->orderBy('sort_order')->orderBy('name')->get()
        );
    }

    public function show(): JsonResponse|GymSubscriptionResource
    {
        $subscription = GymSubscription::query()
            ->whereNotIn('status', [SaasSubscriptionStatus::Cancelled->value, SaasSubscriptionStatus::IncompleteExpired->value])
            ->with('customer')->latest()->first()
            ?? GymSubscription::query()->with('customer')->latest()->first();

        return $subscription
            ? new GymSubscriptionResource($subscription)
            : response()->json(['data' => null]);
    }

    public function invoices(): AnonymousResourceCollection
    {
        return SaasBillingInvoiceResource::collection(
            SaasBillingInvoice::query()->orderByDesc('period_end')->orderByDesc('created_at')->paginate(25)
        );
    }

    public function paymentOptions(StripePlatformBillingService $stripe): JsonResponse
    {
        return response()->json(['data' => [
            'cash_available' => true,
            'bank_transfer_available' => true,
            'stripe_configured' => $stripe->checkoutConfigured(),
        ]]);
    }

    public function manualPayments(): AnonymousResourceCollection
    {
        return SaasSubscriptionPaymentResource::collection(
            SaasSubscriptionPayment::query()
                ->with(['price.plan', 'corrections.correctedBy:id,name', 'refunds.recordedBy:id,name'])
                ->orderByDesc('created_at')
                ->paginate(25)
        );
    }

    public function correctManualPayment(
        CorrectSaasSubscriptionPaymentRequest $request,
        string $payment,
        AuditService $audit,
    ): SaasSubscriptionPaymentResource {
        $record = SaasSubscriptionPayment::query()->findOrFail($payment);
        $data = $request->validated();
        $correction = DB::transaction(function () use ($record, $data, $request, $audit): SaasPaymentCorrection {
            $correction = SaasPaymentCorrection::query()->create([
                ...collect($data)->except('reason')->all(),
                'saas_subscription_payment_id' => $record->id,
                'corrected_by' => $request->user()->id,
                'reason' => $data['reason'],
                'created_at' => now(),
            ]);
            $audit->record('saas.payment.correction_recorded', $correction, $request->user(), before: [
                'reference' => $record->reference, 'method' => $record->method->value,
                'payment_date' => $record->payment_date?->toDateString(), 'amount_minor' => $record->amount_minor,
            ], after: collect($data)->except('reason')->all(), reason: $data['reason'], request: $request);

            return $correction;
        });

        return new SaasSubscriptionPaymentResource($record->fresh()->load(['price.plan', 'corrections.correctedBy:id,name', 'refunds.recordedBy:id,name']));
    }

    public function ironCoreReceipt(string $payment): Response
    {
        $record = SaasSubscriptionPayment::query()->with(['price.plan', 'invoice', 'corrections'])->findOrFail($payment);
        abort_unless(in_array($record->status->value, ['paid', 'partially_refunded', 'refunded'], true), 422, 'Only settled transactions have an IronCore receipt.');
        $effectiveMethod = $record->corrections->reverse()->first(fn ($item) => $item->method !== null)?->method?->value ?? $record->method->value;
        $effectiveAmount = $record->corrections->reverse()->first(fn ($item) => $item->amount_minor !== null)?->amount_minor ?? $record->amount_minor;
        $effectiveDate = $record->corrections->reverse()->first(fn ($item) => $item->payment_date !== null)?->payment_date?->toDateString()
            ?? $record->payment_date?->toDateString()
            ?? $record->paid_at?->toDateString();
        $lines = [
            'Gym: '.app(TenantContext::class)->gym()->name,
            'SaaS plan: '.$record->price->plan->name,
            'Invoice number: '.($record->invoice?->number ?? 'Not issued'),
            'Receipt number: ICR-'.mb_strtoupper(mb_substr(str_replace('-', '', $record->id), 0, 12)),
            'Payment method: '.str_replace('_', ' ', $effectiveMethod),
            'Amount: '.number_format($effectiveAmount / 100, 2).' '.$record->currency->value,
            'Paid date: '.$effectiveDate,
            'Refunded amount: '.number_format($record->refunded_amount_minor / 100, 2).' '.$record->currency->value,
            'Billing period: '.($record->invoice?->period_start?->toDateString() ?? '—').' to '.($record->invoice?->period_end?->toDateString() ?? '—'),
            'Status: '.str_replace('_', ' ', ucfirst($record->status->value)),
        ];

        return response(SimplePdfDocument::fromLines($lines, 'IRONCORE · SaaS Receipt'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="ironcore-saas-receipt.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function exportPayments(Request $request): Response|StreamedResponse
    {
        $format = mb_strtolower((string) $request->query('format', 'csv'));
        abort_unless(in_array($format, ['csv', 'xlsx', 'pdf'], true), 422, 'Export format must be csv, xlsx or pdf.');
        $rows = [['Created', 'Gym', 'Plan', 'Method', 'Reference', 'Amount', 'Refunded amount', 'Currency', 'Status', 'Payment date']];
        SaasSubscriptionPayment::query()->with(['price.plan', 'corrections'])->chunkById(500, function ($payments) use (&$rows): void {
            foreach ($payments as $payment) {
                $effectiveMethod = $payment->corrections->reverse()->first(fn ($item) => $item->method !== null)?->method?->value ?? $payment->method->value;
                $effectiveReference = $payment->corrections->reverse()->first(fn ($item) => $item->reference !== null)?->reference ?? $payment->reference;
                $effectiveAmount = $payment->corrections->reverse()->first(fn ($item) => $item->amount_minor !== null)?->amount_minor ?? $payment->amount_minor;
                $effectiveDate = $payment->corrections->reverse()->first(fn ($item) => $item->payment_date !== null)?->payment_date?->toDateString()
                    ?? $payment->payment_date?->toDateString()
                    ?? $payment->paid_at?->toDateString();
                $rows[] = [$payment->created_at?->toIso8601String(), app(TenantContext::class)->gym()->name, $payment->price->plan->name,
                    $effectiveMethod, $effectiveReference,
                    $effectiveAmount, $payment->refunded_amount_minor, $payment->currency->value, $payment->status->value, $effectiveDate];
            }
        }, 'id');
        $name = 'ironcore-saas-payments-'.now()->format('Ymd-His');
        if ($format === 'xlsx') {
            return response((string) SimpleXLSXGen::fromArray($rows), 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => "attachment; filename=\"{$name}.xlsx\"", 'Cache-Control' => 'private, no-store']);
        }
        if ($format === 'pdf') {
            return response(SimplePdfDocument::fromLines(array_map(fn (array $row): string => implode(' | ', $row), array_slice($rows, 1)), 'IRONCORE · SaaS Payment Report'), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename=\"{$name}.pdf\"", 'Cache-Control' => 'private, no-store']);
        }
        return response()->streamDownload(function () use ($rows): void { $stream = fopen('php://output', 'wb'); fwrite($stream, "\xEF\xBB\xBF"); foreach ($rows as $row) fputcsv($stream, $row); fclose($stream); }, "{$name}.csv", ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function storeManualPayment(
        StoreSaasSubscriptionPaymentRequest $request,
        SaasBillingService $billing,
        TenantContext $context,
    ): JsonResponse {
        $price = $request->validated('saas_plan_price_id')
            ? SaasPlanPrice::query()->with('plan')->findOrFail($request->validated('saas_plan_price_id'))
            : null;
        $result = $billing->createManualPayment(
            $context->gym(),
            $price,
            $request->safe()->except('receipt'),
            $request->user(),
            $request,
            $request->file('receipt'),
        );

        return response()->json([
            'data' => (new SaasSubscriptionPaymentResource($result['payment']))->resolve($request),
            'meta' => ['idempotency_reused' => $result['reused']],
        ], $result['reused'] ? 200 : 201);
    }

    public function reviewManualPayment(
        ReviewSaasSubscriptionPaymentRequest $request,
        string $payment,
        SaasBillingService $billing,
        TenantContext $context,
    ): SaasSubscriptionPaymentResource {
        $model = SaasSubscriptionPayment::query()->findOrFail($payment);

        return new SaasSubscriptionPaymentResource($billing->reviewManualPayment(
            $context->gym(), $model, $request->validated(), $request->user(), $request,
        ));
    }

    public function refundManualPayment(
        StoreSaasPaymentRefundRequest $request,
        string $payment,
        SaasBillingService $billing,
        TenantContext $context,
    ): SaasSubscriptionPaymentResource {
        $record = SaasSubscriptionPayment::query()->findOrFail($payment);

        return new SaasSubscriptionPaymentResource($billing->refundManualPayment(
            $context->gym(), $record, $request->validated(), $request->user(), $request,
        ));
    }

    public function voidInvoice(
        VoidSaasInvoiceRequest $request,
        string $invoice,
        SaasBillingService $billing,
    ): SaasBillingInvoiceResource {
        return new SaasBillingInvoiceResource($billing->voidInvoice(
            SaasBillingInvoice::query()->findOrFail($invoice),
            $request->validated('reason'),
            $request->user(),
            $request,
        ));
    }

    public function overrideBillingRestriction(
        OverrideSaasBillingRestrictionRequest $request,
        SaasBillingService $billing,
        TenantContext $context,
    ): GymSubscriptionResource {
        return new GymSubscriptionResource($billing->overrideBillingRestriction(
            $context->gym(), $request->validated(), $request->user(), $request,
        ));
    }

    public function manualPaymentReceipt(string $payment): StreamedResponse
    {
        $record = SaasSubscriptionPayment::query()->findOrFail($payment);
        abort_unless(filled($record->receipt_path), 404);
        $disk = Storage::disk($record->receipt_disk);
        abort_unless($disk->exists($record->receipt_path), 404);
        $disposition = (new ResponseHeaderBag())->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $record->receipt_original_name,
            'saas-bank-transfer-receipt',
        );

        return response()->stream(function () use ($disk, $record): void {
            $stream = $disk->readStream($record->receipt_path);
            abort_unless(is_resource($stream), 404);
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $record->receipt_mime_type,
            'Content-Length' => (string) $record->receipt_size_bytes,
            'Content-Disposition' => $disposition,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function checkout(
        StartSaasCheckoutRequest $request,
        SaasBillingService $billing,
        TenantContext $context,
    ): JsonResponse {
        $price = SaasPlanPrice::query()->findOrFail($request->validated('saas_plan_price_id'));
        $result = $billing->startCheckout($context->gym(), $price, $request->validated('idempotency_key'), $request->user());

        return response()->json(['data' => $result]);
    }

    public function portal(SaasBillingService $billing): JsonResponse
    {
        return response()->json(['data' => $billing->createPortal()]);
    }
}
