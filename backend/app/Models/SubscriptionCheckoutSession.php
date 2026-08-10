<?php

namespace App\Models;

use App\Enums\SubscriptionCheckoutStatus;
use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionCheckoutSession extends Model
{
    use BelongsToGym, HasUuids;

    protected $fillable = [
        'gym_id', 'created_by', 'saas_plan_price_id', 'idempotency_key',
        'provider_session_id', 'status', 'expires_at', 'completed_at',
    ];

    protected $hidden = ['provider_session_id', 'idempotency_key'];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionCheckoutStatus::class,
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function price(): BelongsTo
    {
        return $this->belongsTo(SaasPlanPrice::class, 'saas_plan_price_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
