<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaasPaymentApprovalReversal extends Model
{
    use BelongsToGym, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'gym_id', 'saas_subscription_payment_id', 'reversed_by',
        'previous_payment_status', 'invoice_status_before',
        'invoice_amount_paid_before', 'invoice_amount_remaining_before',
        'subscription_status_before', 'reason', 'reversed_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'invoice_amount_paid_before' => 'integer',
            'invoice_amount_remaining_before' => 'integer',
            'reversed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SaasSubscriptionPayment::class, 'saas_subscription_payment_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
