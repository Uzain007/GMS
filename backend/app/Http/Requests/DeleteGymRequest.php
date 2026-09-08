<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeleteGymRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'string', 'max:160'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
