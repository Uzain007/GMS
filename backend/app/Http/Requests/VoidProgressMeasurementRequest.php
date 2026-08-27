<?php

namespace App\Http\Requests;

class VoidProgressMeasurementRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            // "Delete" is a reversible-in-evidence void, never a destructive
            // database delete of member progress history.
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
