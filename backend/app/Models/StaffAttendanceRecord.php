<?php

namespace App\Models;

use App\Enums\AttendanceMethod;
use App\Enums\AttendanceStatus;
use App\Enums\CheckoutType;
use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffAttendanceRecord extends Model
{
    use BelongsToGym, HasUuids;

    protected $fillable = [
        'gym_id', 'staff_profile_id', 'branch_id', 'checked_in_by', 'checked_out_by',
        'method', 'status', 'checkout_type', 'checked_in_at', 'checked_out_at',
    ];

    protected function casts(): array
    {
        return [
            'method' => AttendanceMethod::class,
            'status' => AttendanceStatus::class,
            'checkout_type' => CheckoutType::class,
            'checked_in_at' => 'immutable_datetime',
            'checked_out_at' => 'immutable_datetime',
        ];
    }

    public function staffProfile(): BelongsTo { return $this->belongsTo(StaffProfile::class); }
    public function branch(): BelongsTo { return $this->belongsTo(GymBranch::class); }
}
