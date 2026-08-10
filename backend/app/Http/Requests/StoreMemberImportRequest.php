<?php

namespace App\Http\Requests;

class StoreMemberImportRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            // Ten-megabyte limit bounds upload/queue work; rows stream in chunks.
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ];
    }
}
