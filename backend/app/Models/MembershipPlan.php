<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Enums\Currency;
use App\Enums\PlanStatus;
use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MembershipPlan extends Model
{
    use BelongsToGym, HasFactory, HasUuids;

    protected $fillable = [
        'branch_id', 'name', 'code', 'description', 'billing_interval', 'interval_count',
        'price_amount_minor', 'currency', 'joining_fee_minor', 'duration_days',
        'trial_days', 'status', 'terms',
    ];

    protected function casts(): array
    {
        return [
            'billing_interval' => BillingInterval::class,
            'currency' => Currency::class,
            'status' => PlanStatus::class,
            'price_amount_minor' => 'integer',
            'joining_fee_minor' => 'integer',
            'interval_count' => 'integer',
            'duration_days' => 'integer',
            'trial_days' => 'integer',
            'terms' => 'array',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(GymBranch::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'plan_id');
    }
}
