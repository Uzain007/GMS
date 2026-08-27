<?php

namespace App\Http\Requests;

use App\Enums\Currency;
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
            'payment_methods' => ['sometimes', 'array', 'min:1', 'max:3'],
            'payment_methods.*' => ['required_with:payment_methods', 'distinct', Rule::in(['cash', 'bank_transfer', 'stripe'])],
            // A price edit appends a new immutable row inside the same
            // transaction as catalogue details; accepted subscriptions keep snapshots.
            'price' => ['sometimes', 'array:currency,billing_interval,amount_minor,trial_days'],
            'price.currency' => ['required_with:price', Rule::enum(Currency::class)],
            'price.billing_interval' => ['required_with:price', Rule::in(['monthly', 'yearly'])],
            'price.amount_minor' => ['required_with:price', 'integer', 'between:1,999999999999'],
            'price.trial_days' => ['sometimes', 'integer', 'between:0,90'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
