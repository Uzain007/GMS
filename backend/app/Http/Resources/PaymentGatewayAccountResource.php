<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentGatewayAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider->value,
            'status' => $this->status->value,
            'charges_enabled' => $this->charges_enabled,
            'payouts_enabled' => $this->payouts_enabled,
            'details_submitted' => $this->details_submitted,
            'country_code' => $this->country_code,
            'default_currency' => $this->default_currency->value,
            'requirements' => $this->requirements,
            'connected_at' => $this->connected_at?->toIso8601String(),
            // Only an opaque account reference is exposed; platform credentials
            // and member payment details never enter this resource.
            'provider_account_id' => $this->provider_account_id,
        ];
    }
}
