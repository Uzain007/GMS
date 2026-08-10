<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\PaymentProvider;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaasPlanPrice extends Model
{
    use HasUuids;

    protected $fillable = [
        'saas_plan_id', 'currency', 'billing_interval', 'amount_minor',
        'trial_days', 'active', 'provider', 'provider_price_id',
    ];

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'provider' => PaymentProvider::class,
            'amount_minor' => 'integer',
            'trial_days' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SaasPlan::class, 'saas_plan_id');
    }
}
