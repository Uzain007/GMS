<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\RefundStatus;
use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentRefund extends Model
{
    use BelongsToGym, HasUuids;

    protected $fillable = [
        'payment_id', 'recorded_by', 'status', 'amount_minor', 'currency',
        'provider_refund_id', 'reason', 'refunded_at', 'failure_code', 'failure_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'amount_minor' => 'integer',
            'currency' => Currency::class,
            'refunded_at' => 'immutable_datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
