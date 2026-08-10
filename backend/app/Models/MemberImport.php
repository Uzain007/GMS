<?php

namespace App\Models;

use App\Enums\ImportStatus;
use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberImport extends Model
{
    use BelongsToGym, HasFactory, HasUuids;

    protected $fillable = [
        'requested_by', 'original_name', 'storage_disk', 'storage_path', 'status',
        'total_rows', 'processed_rows', 'success_rows', 'failure_rows', 'errors',
        'started_at', 'completed_at',
    ];

    protected $hidden = ['storage_disk', 'storage_path'];

    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'success_rows' => 'integer',
            'failure_rows' => 'integer',
            'errors' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
