<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaasPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status->value,
            'feature_limits' => $this->feature_limits,
            'sort_order' => $this->sort_order,
            // Provider catalogue identifiers remain server-only.
            'prices' => SaasPlanPriceResource::collection($this->whenLoaded('prices')),
            'created_at' => $this->created_at,
        ];
    }
}
