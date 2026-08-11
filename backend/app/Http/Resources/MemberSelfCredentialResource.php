<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberSelfCredentialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Internal credential, member and tenant identifiers are unnecessary
        // for the pass UI and are deliberately excluded.
        return [
            'credential_hint' => $this->credential_hint,
            'status' => $this->status->value,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
