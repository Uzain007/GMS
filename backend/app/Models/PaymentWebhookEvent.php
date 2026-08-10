<?php

namespace App\Models;

use App\Enums\PaymentProvider;
use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaymentWebhookEvent extends Model
{
    use BelongsToGym, HasUuids;

    protected $fillable = [
        'provider', 'provider_account_id', 'provider_event_id', 'event_type',
        'payload_hash', 'status', 'processed_at', 'error',
    ];

    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'processed_at' => 'immutable_datetime',
        ];
    }
}
