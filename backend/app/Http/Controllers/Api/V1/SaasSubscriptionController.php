<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SaasPlanStatus;
use App\Enums\SaasSubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StartSaasCheckoutRequest;
use App\Http\Requests\StoreSaasSubscriptionPaymentRequest;
use App\Http\Requests\ReviewSaasSubscriptionPaymentRequest;
use App\Http\Resources\GymSubscriptionResource;
use App\Http\Resources\SaasBillingInvoiceResource;
use App\Http\Resources\SaasPlanResource;
use App\Http\Resources\SaasSubscriptionPaymentResource;
use App\Models\GymSubscription;
use App\Models\SaasBillingInvoice;
use App\Models\SaasPlan;
use App\Models\SaasPlanPrice;
use App\Models\SaasSubscriptionPayment;
use App\Services\SaasBillingService;
use App\Services\StripePlatformBillingService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
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
                ->with('price.plan')
                ->orderByDesc('created_at')
                ->paginate(25)
        );
    }

    public function storeManualPayment(
        StoreSaasSubscriptionPaymentRequest $request,
        SaasBillingService $billing,
        TenantContext $context,
    ): JsonResponse {
        $price = SaasPlanPrice::query()->with('plan')->findOrFail($request->validated('saas_plan_price_id'));
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
