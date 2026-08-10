<?php

namespace App\Http\Requests;

use App\Enums\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaasPlanPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    public function rules(): array
    {
        return [
            'currency' => ['required', Rule::enum(Currency::class)],
            'billing_interval' => ['required', Rule::in(['monthly', 'yearly'])],
            'amount_minor' => ['required', 'integer', 'between:1,999999999999'],
            'trial_days' => ['sometimes', 'integer', 'between:0,90'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
