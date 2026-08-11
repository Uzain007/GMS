<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberSelfResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // The member portal receives only fields it can display or edit.
        // Tenant/user UUIDs and private staff metadata stay server-side.
        return [
            'member_number' => $this->member_number,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'status' => $this->status->value,
            'joined_at' => $this->joined_at?->toIso8601String(),
        ];
    }
}
