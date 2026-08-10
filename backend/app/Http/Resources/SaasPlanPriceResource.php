<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaasPlanPriceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'currency' => $this->currency->value,
            'billing_interval' => $this->billing_interval,
            'amount_minor' => $this->amount_minor,
            'trial_days' => $this->trial_days,
            'active' => $this->active,
        ];
    }
}
