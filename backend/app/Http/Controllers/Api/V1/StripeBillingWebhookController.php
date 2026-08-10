<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\StripeBillingWebhookService;
use App\Services\StripePlatformBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class StripeBillingWebhookController extends Controller
{
    public function handle(
        Request $request,
        StripePlatformBillingService $stripe,
        StripeBillingWebhookService $webhooks,
    ): JsonResponse {
        $payload = $request->getContent();
        try {
            // The Billing endpoint has a separate signing secret from Connect;
            // verification always precedes the narrow customer lookup policy.
            $event = $stripe->verifyBillingWebhook($payload, (string) $request->header('Stripe-Signature'));
            $result = $webhooks->process($event, $payload);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 400);
        }
        return response()->json(['received' => true, 'duplicate' => $result['duplicate']]);
    }
}
