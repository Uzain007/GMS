<?php

namespace App\Http\Requests;

use App\Enums\Currency;
use App\Enums\GymStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGymRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:160'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'base_currency' => ['sometimes', Rule::enum(Currency::class)],
            'country_code' => ['sometimes', 'string', 'size:2'],
            'timezone' => ['sometimes', 'timezone'],
            // Tenant operators may edit their business settings, but only a
            // platform administrator can change the tenant lifecycle itself.
            'status' => [
                'sometimes',
                Rule::enum(GymStatus::class),
                Rule::prohibitedIf(fn (): bool => ! $this->user()?->isSuperAdmin()),
            ],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
