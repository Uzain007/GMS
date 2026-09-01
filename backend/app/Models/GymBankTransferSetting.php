<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GymBankTransferSetting extends Model
{
    use BelongsToGym, HasUuids;

    protected $fillable = [
        'enabled', 'account_name', 'bank_name', 'account_number_or_iban',
        'routing_details', 'payment_instructions', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'account_name' => 'encrypted',
            'bank_name' => 'encrypted',
            'account_number_or_iban' => 'encrypted',
            'routing_details' => 'encrypted',
            'payment_instructions' => 'encrypted',
        ];
    }

    public function gym(): BelongsTo { return $this->belongsTo(Gym::class); }
    public function updatedBy(): BelongsTo { return $this->belongsTo(User::class, 'updated_by'); }
}
