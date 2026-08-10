<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SaasBillingWebhookEvent extends Model
{
    use BelongsToGym, HasUuids;

    protected $fillable = [
        'gym_id', 'provider_customer_id', 'provider_event_id', 'event_type',
        'payload_hash', 'status', 'processed_at', 'error',
    ];

    protected $hidden = ['provider_customer_id', 'provider_event_id', 'payload_hash'];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }
}
