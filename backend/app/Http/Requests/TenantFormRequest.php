<?php

namespace App\Http\Requests;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

abstract class TenantFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route roles/policies decide capability; validation below guarantees
        // that referenced operational records belong to the resolved gym.
        return app(TenantContext::class)->hasTenant();
    }

    protected function tenantId(): string
    {
        return app(TenantContext::class)->id();
    }

    protected function tenantToday(): string
    {
        // Calendar-only business dates follow the selected gym, not the API
        // server's UTC day, so valid local payments are not rejected at midnight.
        return now(app(TenantContext::class)->gym()->timezone)->toDateString();
    }

    protected function tenantExists(string $table, string $column = 'id'): Exists
    {
        return Rule::exists($table, $column)
            ->where(fn ($query) => $query->where('gym_id', $this->tenantId()));
    }

    protected function tenantUnique(string $table, string $column): Unique
    {
        return Rule::unique($table, $column)
            ->where(fn ($query) => $query->where('gym_id', $this->tenantId()));
    }
}
