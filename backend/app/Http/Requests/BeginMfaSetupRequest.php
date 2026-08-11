<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BeginMfaSetupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['current_password' => ['required', 'string', 'max:1024']];
    }
}
