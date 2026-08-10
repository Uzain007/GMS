<?php

namespace App\Models;

use App\Enums\AttendanceMethod;
use App\Enums\AttendanceStatus;
use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    use BelongsToGym, HasUuids;

    protected $fillable = [
        'member_id', 'membership_id', 'branch_id', 'access_credential_id',
        'checked_in_by', 'checked_out_by', 'method', 'status',
        'checked_in_at', 'checked_out_at',
    ];

    protected function casts(): array
    {
        return [
            'method' => AttendanceMethod::class,
            'status' => AttendanceStatus::class,
            'checked_in_at' => 'immutable_datetime',
            'checked_out_at' => 'immutable_datetime',
        ];
    }

    public function member(): BelongsTo { return $this->belongsTo(Member::class); }
    public function membership(): BelongsTo { return $this->belongsTo(Membership::class); }
    public function branch(): BelongsTo { return $this->belongsTo(GymBranch::class); }
    public function accessCredential(): BelongsTo { return $this->belongsTo(MemberAccessCredential::class); }
    public function checkedInBy(): BelongsTo { return $this->belongsTo(User::class, 'checked_in_by'); }
    public function checkedOutBy(): BelongsTo { return $this->belongsTo(User::class, 'checked_out_by'); }
}
