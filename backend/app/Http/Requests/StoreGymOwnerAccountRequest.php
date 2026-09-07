<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreGymOwnerAccountRequest extends TenantFormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() === true;
    }

    public function rules(): array
    {
        $temporary = fn (): bool => $this->input('setup_method') === 'temporary_password';

        return [
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'phone' => ['required', 'string', 'max:40', 'regex:/^[0-9+()\-\s]{7,40}$/'],
            'setup_method' => ['required', Rule::in(['invite', 'temporary_password'])],
            'temporary_password' => [Rule::requiredIf($temporary), 'nullable', 'string', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()->symbols()],
            'temporary_password_confirmation' => [Rule::requiredIf($temporary), 'nullable', 'string'],
            'require_password_change' => [Rule::requiredIf($temporary), 'boolean', 'accepted_if:setup_method,temporary_password'],
            'reason' => ['sometimes', 'required', 'string', 'min:5', 'max:500'],
        ];
    }
}
