<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\PaymentProvider;
use App\Enums\SaasSubscriptionStatus;
use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GymSubscription extends Model
{
    use BelongsToGym, HasUuids;

    protected $fillable = [
        'gym_id', 'billing_customer_id', 'saas_plan_id', 'saas_plan_price_id',
        'provider', 'provider_subscription_id', 'status', 'plan_code_snapshot',
        'plan_name_snapshot', 'feature_limits_snapshot', 'currency', 'amount_minor',
        'billing_interval', 'current_period_start', 'current_period_end',
        'trial_ends_at', 'cancel_at_period_end', 'cancelled_at', 'ended_at',
        'latest_invoice_id', 'failure_code', 'failure_message',
    ];

    protected $hidden = ['provider_subscription_id'];

    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'status' => SaasSubscriptionStatus::class,
            'feature_limits_snapshot' => 'array',
            'currency' => Currency::class,
            'amount_minor' => 'integer',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'trial_ends_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'cancelled_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(PlatformBillingCustomer::class, 'billing_customer_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SaasPlan::class, 'saas_plan_id');
    }

    public function price(): BelongsTo
    {
        return $this->belongsTo(SaasPlanPrice::class, 'saas_plan_price_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SaasBillingInvoice::class);
    }
}
