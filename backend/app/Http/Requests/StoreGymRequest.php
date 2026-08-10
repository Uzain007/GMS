<?php

namespace App\Http\Requests;

use App\Enums\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGymRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'slug' => ['nullable', 'alpha_dash:ascii', 'max:100', 'unique:gyms,slug'],
            'base_currency' => ['required', Rule::enum(Currency::class)],
            'country_code' => ['required', 'string', 'size:2'],
            'timezone' => ['required', 'timezone'],
            'owner.name' => ['required', 'string', 'max:160'],
            'owner.email' => ['required', 'email:rfc', 'max:254'],
        ];
    }
}
