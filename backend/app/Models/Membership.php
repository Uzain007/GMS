<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Enums\Currency;
use App\Enums\MembershipStatus;
use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Membership extends Model
{
    use BelongsToGym, HasFactory, HasUuids;

    protected $fillable = [
        'member_id', 'plan_id', 'branch_id', 'created_by', 'status', 'starts_at',
        'ends_at', 'next_billing_at', 'price_amount_minor', 'currency',
        'joining_fee_minor', 'billing_interval', 'interval_count', 'auto_renew',
        'cancelled_at', 'cancellation_reason', 'terms_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'starts_at' => 'date',
            'ends_at' => 'date',
            'next_billing_at' => 'date',
            'price_amount_minor' => 'integer',
            'joining_fee_minor' => 'integer',
            'currency' => Currency::class,
            'billing_interval' => BillingInterval::class,
            'interval_count' => 'integer',
            'auto_renew' => 'boolean',
            'cancelled_at' => 'immutable_datetime',
            'terms_snapshot' => 'array',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'plan_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(GymBranch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
