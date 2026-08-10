<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\StripeGatewayService;
use App\Services\StripeWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class StripeWebhookController extends Controller
{
    public function handle(Request $request, StripeGatewayService $stripe, StripeWebhookService $webhooks): JsonResponse
    {
        $payload = $request->getContent();
        try {
            // Verification precedes the narrow provider-account lookup policy.
            $event = $stripe->verifyWebhook($payload, (string) $request->header('Stripe-Signature'));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 400);
        }

        $result = $webhooks->process($event, $payload);
        return response()->json(['received' => true, 'duplicate' => $result['duplicate']]);
    }
}
