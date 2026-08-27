<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaasSubscriptionPayment extends Model
{
    use BelongsToGym, HasUuids;

    protected $fillable = [
        'gym_id', 'saas_plan_price_id', 'gym_subscription_id',
        'saas_billing_invoice_id', 'submitted_by', 'reviewed_by', 'method',
        'status', 'amount_minor', 'currency', 'idempotency_key', 'reference',
        'receipt_disk', 'receipt_path', 'receipt_original_name',
        'receipt_mime_type', 'receipt_size_bytes', 'receipt_sha256',
        'reviewed_at', 'review_reason', 'paid_at',
    ];

    protected $hidden = [
        'idempotency_key', 'receipt_disk', 'receipt_path', 'receipt_sha256',
    ];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'currency' => Currency::class,
            'amount_minor' => 'integer',
            'receipt_size_bytes' => 'integer',
            'reviewed_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
        ];
    }

    public function price(): BelongsTo
    {
        return $this->belongsTo(SaasPlanPrice::class, 'saas_plan_price_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(GymSubscription::class, 'gym_subscription_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SaasBillingInvoice::class, 'saas_billing_invoice_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
