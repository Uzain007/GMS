<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasUuids;

    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'before_values' => 'encrypted:array',
            'after_values' => 'encrypted:array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
