<?php

namespace App\Http\Requests;

use App\Enums\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaasPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'alpha_dash:ascii', 'max:60', 'unique:saas_plans,code'],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['sometimes', 'integer', 'between:0,1000'],
            'feature_limits' => ['required', 'array:members,branches,staff,advanced_reports,priority_support'],
            'feature_limits.members' => ['required', 'integer', 'between:1,10000000'],
            'feature_limits.branches' => ['required', 'integer', 'between:1,10000'],
            'feature_limits.staff' => ['required', 'integer', 'between:1,100000'],
            'feature_limits.advanced_reports' => ['required', 'boolean'],
            'feature_limits.priority_support' => ['required', 'boolean'],
            'currency' => ['required', Rule::enum(Currency::class)],
            'billing_interval' => ['required', Rule::in(['monthly', 'yearly'])],
            'amount_minor' => ['required', 'integer', 'between:1,999999999999'],
            'trial_days' => ['sometimes', 'integer', 'between:0,90'],
        ];
    }
}
