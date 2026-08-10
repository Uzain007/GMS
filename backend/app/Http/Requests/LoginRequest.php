<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'max:1024'],
            'device_name' => ['sometimes', 'string', 'max:120'],
            // Browser clients use the encrypted Sanctum session by default;
            // native clients must explicitly opt into a revocable bearer token.
            'use_bearer_token' => ['sometimes', 'boolean'],
        ];
    }
}
