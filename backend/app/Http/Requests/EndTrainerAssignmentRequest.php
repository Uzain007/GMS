<?php

namespace App\Http\Requests;

class EndTrainerAssignmentRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            // Ending a coaching boundary is sensitive and always leaves audit evidence.
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
