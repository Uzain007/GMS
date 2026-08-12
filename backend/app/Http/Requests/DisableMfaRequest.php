<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DisableMfaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:1024'],
            'code' => ['nullable', 'required_without:recovery_code', 'prohibits:recovery_code', 'string', 'digits:6'],
            'recovery_code' => ['nullable', 'required_without:code', 'prohibits:code', 'string', 'max:64'],
        ];
    }
}
