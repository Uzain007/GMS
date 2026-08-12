<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SaasPlanStatus;
use App\Enums\SaasSubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StartSaasCheckoutRequest;
use App\Http\Resources\GymSubscriptionResource;
use App\Http\Resources\SaasBillingInvoiceResource;
use App\Http\Resources\SaasPlanResource;
use App\Models\GymSubscription;
use App\Models\SaasBillingInvoice;
use App\Models\SaasPlan;
use App\Models\SaasPlanPrice;
use App\Services\SaasBillingService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
