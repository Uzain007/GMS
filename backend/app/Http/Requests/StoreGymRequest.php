<?php

namespace App\Http\Requests;

use App\Enums\Currency;
use App\Support\IsoCountryCodes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGymRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('country_code'))) {
            $this->merge(['country_code' => mb_strtoupper(trim($this->input('country_code')))]);
        }
    }

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
            'country_code' => ['required', 'string', Rule::in(IsoCountryCodes::ALL)],
            'timezone' => ['required', 'timezone'],
            'owner.name' => ['required', 'string', 'max:160'],
            'owner.email' => ['required', 'email:rfc', 'max:254'],
        ];
    }
}
