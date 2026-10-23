<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipRenewal extends Model
{
    use BelongsToGym, HasUuids;

    protected $fillable = [
        'gym_id', 'membership_id', 'invoice_id', 'period_start', 'period_end',
        'status', 'source', 'renewed_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'renewed_at' => 'immutable_datetime',
        ];
    }

    public function membership(): BelongsTo { return $this->belongsTo(Membership::class); }
    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
}
