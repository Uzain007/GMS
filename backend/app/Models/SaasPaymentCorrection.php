<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaasPaymentCorrection extends Model
{
    use BelongsToGym, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'gym_id', 'saas_subscription_payment_id', 'corrected_by', 'reference',
        'method', 'payment_date', 'amount_minor', 'internal_notes', 'metadata', 'reason', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'payment_date' => 'immutable_date',
            'amount_minor' => 'integer',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SaasSubscriptionPayment::class, 'saas_subscription_payment_id');
    }

    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }
}
