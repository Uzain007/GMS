<?php

namespace App\Http\Requests;

use App\Enums\ProgressMetric;
use Illuminate\Validation\Rule;

class StoreProgressMeasurementRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'member_id' => ['nullable', 'uuid', $this->tenantExists('members')],
            'metric' => ['required', Rule::enum(ProgressMetric::class)],
            'value_milli' => ['required', 'integer', 'min:-1000000000', 'max:1000000000'],
            'unit' => ['required', 'string', Rule::in(['kg', 'percent', 'cm', 'count', 'seconds', 'metres', 'custom'])],
            'measured_at' => ['required', 'date', 'before_or_equal:now'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
