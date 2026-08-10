<?php

namespace App\Models\Concerns;

use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

trait BelongsToGym
{
    public static function bootBelongsToGym(): void
    {
        static::addGlobalScope('gym', function (Builder $builder): void {
            $context = app(TenantContext::class);
            if ($context->hasTenant()) {
                $builder->where($builder->qualifyColumn('gym_id'), $context->id());
                return;
            }

            // Tenant models fail closed when no gym is resolved. Platform-wide
            // work must use a dedicated audited service instead of ordinary ORM.
            $builder->whereRaw('1 = 0');
        });

        static::saving(function (Model $model): void {
            $context = app(TenantContext::class);

            if (! $context->hasTenant()) {
                throw new LogicException('A tenant context is required to save tenant-owned data.');
            }

            if (blank($model->getAttribute('gym_id'))) {
                $model->setAttribute('gym_id', $context->id());
            }

            if ((string) $model->getAttribute('gym_id') !== $context->id()) {
                throw new LogicException('Tenant-owned data cannot be saved for another gym.');
            }

            if ($model->exists && $model->isDirty('gym_id')) {
                throw new LogicException('A tenant-owned record cannot be moved between gyms.');
            }
        });
    }
}
