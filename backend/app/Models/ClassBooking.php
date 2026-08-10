<?php

namespace App\Models;

use App\Enums\ClassBookingStatus;
use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassBooking extends Model
{
    use BelongsToGym, HasUuids;

    protected $fillable = [
        'class_session_id', 'member_id', 'membership_id', 'booked_by', 'status',
        'waitlist_sequence', 'booked_at', 'promoted_at', 'cancelled_at',
        'checked_in_at', 'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => ClassBookingStatus::class,
            'waitlist_sequence' => 'integer',
            'booked_at' => 'immutable_datetime',
            'promoted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'checked_in_at' => 'immutable_datetime',
        ];
    }

    public function session(): BelongsTo { return $this->belongsTo(ClassSession::class, 'class_session_id'); }
    public function member(): BelongsTo { return $this->belongsTo(Member::class); }
    public function membership(): BelongsTo { return $this->belongsTo(Membership::class); }
    public function bookedBy(): BelongsTo { return $this->belongsTo(User::class, 'booked_by'); }
}
