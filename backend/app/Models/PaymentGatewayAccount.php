<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\PaymentGatewayStatus;
use App\Enums\PaymentProvider;
use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentGatewayAccount extends Model
{
    use BelongsToGym, HasUuids;

    protected $fillable = [
        'provider', 'provider_account_id', 'status', 'charges_enabled',
        'payouts_enabled', 'details_submitted', 'country_code',
        'default_currency', 'requirements', 'connected_at',
    ];

    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'status' => PaymentGatewayStatus::class,
            'charges_enabled' => 'boolean',
            'payouts_enabled' => 'boolean',
            'details_submitted' => 'boolean',
            'default_currency' => Currency::class,
            'requirements' => 'array',
            'connected_at' => 'immutable_datetime',
        ];
    }

    public function gym(): BelongsTo
    {
        return $this->belongsTo(Gym::class);
    }
}
