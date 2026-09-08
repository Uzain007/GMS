<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AuditLogIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'gym_id' => ['nullable', 'uuid'],
            'actor_id' => ['nullable', 'uuid'],
            'role' => ['nullable', 'string', 'max:40'],
            'action' => ['nullable', 'string', 'max:120'],
            'resource' => ['nullable', 'string', 'max:255'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'format' => ['nullable', 'in:csv,xlsx,pdf'],
        ];
    }
}
