<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\PaymentProvider;
use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlatformBillingCustomer extends Model
{
    use BelongsToGym, HasUuids;

    protected $fillable = [
        'gym_id', 'provider', 'provider_customer_id', 'billing_email',
        'billing_name', 'country_code', 'default_currency',
    ];

    protected $hidden = ['provider_customer_id'];

    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'default_currency' => Currency::class,
        ];
    }

    public function gym(): BelongsTo
    {
        return $this->belongsTo(Gym::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(GymSubscription::class, 'billing_customer_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SaasBillingInvoice::class, 'billing_customer_id');
    }
}
