<?php

namespace App\Http\Requests;

use App\Enums\Currency;
use App\Support\IsoCountryCodes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreGymRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('country_code'))) {
            $this->merge(['country_code' => mb_strtoupper(trim($this->input('country_code')))]);
        }
        if (is_string($this->input('owner.email'))) {
            $this->merge(['owner' => array_merge((array) $this->input('owner', []), [
                'email' => mb_strtolower(trim((string) $this->input('owner.email'))),
            ])]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() === true;
    }

    public function rules(): array
    {
        $createsOwner = fn (): bool => $this->boolean('owner.create_login_account');
        $temporary = fn (): bool => $createsOwner() && $this->input('owner.setup_method') === 'temporary_password';

        return [
            'name' => ['required', 'string', 'max:160'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'slug' => ['nullable', 'alpha_dash:ascii', 'max:100', 'unique:gyms,slug'],
            'base_currency' => ['required', Rule::enum(Currency::class)],
            'country_code' => ['required', 'string', Rule::in(IsoCountryCodes::ALL)],
            'timezone' => ['required', 'timezone'],
            'owner.create_login_account' => ['required', 'boolean'],
            'owner.name' => [Rule::requiredIf($createsOwner), 'nullable', 'string', 'max:160'],
            'owner.email' => [Rule::requiredIf($createsOwner), 'nullable', 'email:rfc', 'max:254'],
            'owner.phone' => [Rule::requiredIf($createsOwner), 'nullable', 'string', 'max:40', 'regex:/^[0-9+()\-\s]{7,40}$/'],
            'owner.setup_method' => [Rule::requiredIf($createsOwner), 'nullable', Rule::in(['invite', 'temporary_password'])],
            'owner.temporary_password' => [Rule::requiredIf($temporary), 'nullable', 'string', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()->symbols()],
            'owner.temporary_password_confirmation' => [Rule::requiredIf($temporary), 'nullable', 'string'],
            'owner.require_password_change' => [Rule::requiredIf($temporary), 'boolean', 'accepted_if:owner.setup_method,temporary_password'],
        ];
    }
}
