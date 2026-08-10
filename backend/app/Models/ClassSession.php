<?php

namespace App\Models;

use App\Enums\ClassSessionStatus;
use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassSession extends Model
{
    use BelongsToGym, HasUuids;

    protected $fillable = [
        'branch_id', 'trainer_staff_profile_id', 'created_by', 'title', 'description',
        'starts_at', 'ends_at', 'capacity', 'booked_count', 'waitlist_count',
        'attended_count', 'next_waitlist_sequence', 'waitlist_enabled',
        'booking_opens_at', 'booking_closes_at', 'status', 'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'capacity' => 'integer',
            'booked_count' => 'integer',
            'waitlist_count' => 'integer',
            'attended_count' => 'integer',
            'next_waitlist_sequence' => 'integer',
            'waitlist_enabled' => 'boolean',
            'booking_opens_at' => 'immutable_datetime',
            'booking_closes_at' => 'immutable_datetime',
            'status' => ClassSessionStatus::class,
        ];
    }

    public function branch(): BelongsTo { return $this->belongsTo(GymBranch::class); }
    public function trainer(): BelongsTo { return $this->belongsTo(StaffProfile::class, 'trainer_staff_profile_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function bookings(): HasMany { return $this->hasMany(ClassBooking::class); }
}
