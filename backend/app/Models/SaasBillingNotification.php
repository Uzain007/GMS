<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaasBillingNotification extends Model
{
    use BelongsToGym, HasUuids;

    protected $fillable = [
        'gym_id', 'saas_billing_invoice_id', 'recipient_user_id', 'destination',
        'template_key', 'notification_date', 'idempotency_key', 'status',
        'attempts', 'failure_code', 'sent_at',
    ];

    protected $hidden = ['destination', 'idempotency_key'];

    protected function casts(): array
    {
        return [
            'destination' => 'encrypted',
            'notification_date' => 'immutable_date',
            'attempts' => 'integer',
            'sent_at' => 'immutable_datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SaasBillingInvoice::class, 'saas_billing_invoice_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
