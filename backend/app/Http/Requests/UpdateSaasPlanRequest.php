<?php

namespace App\Http\Requests;

use App\Enums\SaasPlanStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSaasPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::enum(SaasPlanStatus::class)],
            'sort_order' => ['sometimes', 'integer', 'between:0,1000'],
            'feature_limits' => ['sometimes', 'array:members,branches,staff,advanced_reports,priority_support'],
            'feature_limits.members' => ['required_with:feature_limits', 'integer', 'between:1,10000000'],
            'feature_limits.branches' => ['required_with:feature_limits', 'integer', 'between:1,10000'],
            'feature_limits.staff' => ['required_with:feature_limits', 'integer', 'between:1,100000'],
            'feature_limits.advanced_reports' => ['required_with:feature_limits', 'boolean'],
            'feature_limits.priority_support' => ['required_with:feature_limits', 'boolean'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
