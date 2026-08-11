<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberDataExportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'member_id' => $this->member_id,
            'requested_by' => $this->requested_by,
            'status' => $this->status->value,
            'content_sha256' => $this->content_sha256,
            'size_bytes' => $this->size_bytes,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'download_ready' => $this->status->value === 'completed' && $this->expires_at?->isFuture(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
