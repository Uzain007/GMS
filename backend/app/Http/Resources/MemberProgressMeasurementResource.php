<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberProgressMeasurementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'gym_id' => $this->gym_id, 'member_id' => $this->member_id,
            'member' => $this->whenLoaded('member', fn () => ['id' => $this->member->id, 'member_number' => $this->member->member_number, 'name' => trim($this->member->first_name.' '.$this->member->last_name)]),
            // Clients format integer thousandths for display; storage remains exact.
            'metric' => $this->metric->value, 'value_milli' => $this->value_milli, 'unit' => $this->unit,
            'measured_at' => $this->measured_at?->toIso8601String(), 'note' => $this->note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
