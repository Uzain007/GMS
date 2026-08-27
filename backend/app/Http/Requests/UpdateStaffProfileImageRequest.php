<?php

namespace App\Http\Requests;

class UpdateStaffProfileImageRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'profile_image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
