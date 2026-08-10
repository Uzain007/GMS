<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentProvider;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentGatewayAccountResource;
use App\Models\PaymentGatewayAccount;
use App\Services\AuditService;
use App\Services\StripeGatewayService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentGatewayController extends Controller
{
    public function show(): JsonResponse
    {
        $gateway = PaymentGatewayAccount::query()->where('provider', PaymentProvider::Stripe->value)->first();
        return response()->json(['data' => $gateway ? (new PaymentGatewayAccountResource($gateway))->resolve() : null]);
    }

    public function onboard(Request $request, TenantContext $context, StripeGatewayService $stripe, AuditService $audit): JsonResponse
    {
        $result = $stripe->startOnboarding($context->gym(), $request->user()->email);
        $audit->record('payment_gateway.onboarding_started', $result['gateway'], $request->user(), after: [
            'provider' => PaymentProvider::Stripe->value,
            'status' => $result['gateway']->status->value,
        ], request: $request);

        return response()->json([
            'data' => (new PaymentGatewayAccountResource($result['gateway']))->resolve($request),
            'meta' => ['onboarding_url' => $result['onboarding_url']],
        ]);
    }

    public function refresh(Request $request, StripeGatewayService $stripe, AuditService $audit): PaymentGatewayAccountResource
    {
        $gateway = PaymentGatewayAccount::query()->where('provider', PaymentProvider::Stripe->value)->firstOrFail();
        $before = $gateway->toArray();
        $fresh = $stripe->refresh($gateway);
        $audit->record('payment_gateway.refreshed', $fresh, $request->user(), $before, $fresh->toArray(), request: $request);
        return new PaymentGatewayAccountResource($fresh);
    }
}
