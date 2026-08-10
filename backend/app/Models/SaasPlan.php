<?php

namespace App\Models;

use App\Enums\PaymentProvider;
use App\Enums\SaasPlanStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaasPlan extends Model
{
    use HasUuids;

    protected $fillable = [
        'code', 'name', 'description', 'status', 'feature_limits', 'sort_order',
        'provider', 'provider_product_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => SaasPlanStatus::class,
            'provider' => PaymentProvider::class,
            'feature_limits' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(SaasPlanPrice::class);
    }
}
