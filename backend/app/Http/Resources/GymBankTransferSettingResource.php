<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GymBankTransferSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gym_id' => $this->gym_id,
            'enabled' => $this->enabled,
            'account_name' => $this->account_name,
            'bank_name' => $this->bank_name,
            'account_number_or_iban' => $this->account_number_or_iban,
            'routing_details' => $this->routing_details,
            'payment_instructions' => $this->payment_instructions,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
