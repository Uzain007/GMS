<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\RefundStatus;
use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaasPaymentRefund extends Model
{
    use BelongsToGym, HasUuids;

    protected $fillable = [
        'gym_id', 'saas_subscription_payment_id', 'recorded_by', 'status',
        'amount_minor', 'currency', 'reason', 'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'currency' => Currency::class,
            'amount_minor' => 'integer',
            'refunded_at' => 'immutable_datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SaasSubscriptionPayment::class, 'saas_subscription_payment_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
