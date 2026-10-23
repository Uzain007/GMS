<?php

namespace App\Models;

use App\Enums\SaasPlanChangeRequestStatus;
use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaasPlanChangeRequest extends Model
{
    use BelongsToGym, HasUuids;

    protected $fillable = [
        'gym_id', 'gym_subscription_id', 'current_saas_plan_id', 'current_saas_plan_price_id',
        'requested_saas_plan_id', 'requested_saas_plan_price_id', 'requested_by', 'reviewed_by',
        'change_type', 'status', 'reason', 'review_reason', 'requested_at', 'reviewed_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SaasPlanChangeRequestStatus::class,
            'requested_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function subscription(): BelongsTo { return $this->belongsTo(GymSubscription::class, 'gym_subscription_id'); }
    public function gym(): BelongsTo { return $this->belongsTo(Gym::class); }
    public function currentPlan(): BelongsTo { return $this->belongsTo(SaasPlan::class, 'current_saas_plan_id'); }
    public function currentPrice(): BelongsTo { return $this->belongsTo(SaasPlanPrice::class, 'current_saas_plan_price_id'); }
    public function requestedPlan(): BelongsTo { return $this->belongsTo(SaasPlan::class, 'requested_saas_plan_id'); }
    public function requestedPrice(): BelongsTo { return $this->belongsTo(SaasPlanPrice::class, 'requested_saas_plan_price_id'); }
    public function requestedBy(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function reviewedBy(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
}
