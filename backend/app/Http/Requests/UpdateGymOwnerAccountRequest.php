<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateGymOwnerAccountRequest extends TenantFormRequest
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
        return [
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'phone' => ['required', 'string', 'max:40', 'regex:/^[0-9+()\-\s]{7,40}$/'],
            'status' => ['required', Rule::in(['active', 'suspended'])],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
