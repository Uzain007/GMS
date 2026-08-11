<?php

namespace App\Models;

use App\Enums\MemberExportStatus;
use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberDataExport extends Model
{
    use BelongsToGym, HasUuids;

    protected $fillable = [
        'member_id', 'requested_by', 'status', 'storage_disk', 'storage_path',
        'content_sha256', 'size_bytes', 'failure_reason', 'started_at',
        'completed_at', 'expires_at',
    ];

    protected $hidden = ['storage_disk', 'storage_path', 'failure_reason'];

    protected function casts(): array
    {
        return [
            'status' => MemberExportStatus::class,
            'size_bytes' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
