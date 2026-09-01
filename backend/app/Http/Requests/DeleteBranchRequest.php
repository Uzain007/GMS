<?php

namespace App\Http\Requests;

class DeleteBranchRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            // Permanent removal is limited to empty branches and always leaves
            // actor/reason evidence inside the selected tenant audit trail.
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
