<?php

namespace App\Tenancy;

use App\Models\Gym;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

class TenantContext
{
    private ?Gym $gym = null;

    public function set(Gym $gym): void
    {
        // PostgreSQL RLS reads this connection-local value, providing a database
        // boundary even if a future query accidentally bypasses Eloquent scopes.
        $this->setDatabaseTenant($gym->getKey());
        $this->gym = $gym;
    }

    public function gym(): Gym
    {
        return $this->gym ?? throw new LogicException('No gym tenant has been resolved.');
    }

    public function id(): string
    {
        return $this->gym()->getKey();
    }

    public function hasTenant(): bool
    {
        return $this->gym !== null;
    }

    public function clear(): void
    {
        try {
            // Long-running workers may reuse database connections, so the tenant
            // value must be explicitly erased after every request or queued job.
            $this->setDatabaseTenant(null);
        } finally {
            $this->gym = null;
        }
    }

    public function run(Gym $gym, Closure $callback): mixed
    {
        $previous = $this->gym;
        $this->set($gym);

        try {
            return $callback();
        } finally {
            $previous ? $this->set($previous) : $this->clear();
        }
    }

    private function setDatabaseTenant(?string $gymId): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            "select set_config('ironcore.current_gym_id', ?, false)",
            [$gymId ?? '']
        );
    }
}
