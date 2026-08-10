<?php

namespace App\Models;

use App\Enums\AccessCredentialStatus;
use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberAccessCredential extends Model
{
    use BelongsToGym, HasUuids;

    protected $fillable = [
        'member_id', 'issued_by', 'credential_hash', 'credential_hint', 'status',
        'expires_at', 'last_used_at', 'revoked_at',
    ];

    protected $hidden = ['credential_hash'];

    protected function casts(): array
    {
        return [
            'status' => AccessCredentialStatus::class,
            'expires_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
