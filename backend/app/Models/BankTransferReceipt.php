<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankTransferReceipt extends Model
{
    use BelongsToGym, HasUuids;

    protected $fillable = [
        'payment_id', 'member_id', 'membership_id', 'invoice_id', 'submitted_by',
        'reviewed_by', 'bank_reference', 'storage_disk', 'storage_path',
        'original_name', 'mime_type', 'size_bytes', 'content_sha256',
        'reviewed_at', 'review_reason',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
    public function member(): BelongsTo { return $this->belongsTo(Member::class); }
    public function membership(): BelongsTo { return $this->belongsTo(Membership::class); }
    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
    public function submittedBy(): BelongsTo { return $this->belongsTo(User::class, 'submitted_by'); }
    public function reviewedBy(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
}
