<?php

namespace App\Http\Requests;

class CancelClassBookingRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }
}
