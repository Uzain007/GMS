<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\SaasInvoiceStatus;
use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaasBillingInvoice extends Model
{
    use BelongsToGym, HasUuids;

    protected $fillable = [
        'gym_id', 'billing_customer_id', 'gym_subscription_id', 'provider_invoice_id',
        'number', 'status', 'currency', 'amount_due_minor', 'amount_paid_minor',
        'amount_remaining_minor', 'hosted_invoice_url', 'invoice_pdf_url',
        'available_at', 'period_start', 'period_end', 'due_at', 'grace_ends_at',
        'paid_at', 'voided_at', 'voided_by', 'void_reason',
    ];

    protected $hidden = ['provider_invoice_id'];

    protected function casts(): array
    {
        return [
            'status' => SaasInvoiceStatus::class,
            'currency' => Currency::class,
            'amount_due_minor' => 'integer',
            'amount_paid_minor' => 'integer',
            'amount_remaining_minor' => 'integer',
            'available_at' => 'datetime',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'due_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(PlatformBillingCustomer::class, 'billing_customer_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(GymSubscription::class, 'gym_subscription_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
