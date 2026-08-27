<?php

namespace App\Http\Requests;

use App\Enums\ProgressMetric;
use Illuminate\Validation\Rule;

class CorrectProgressMeasurementRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'metric' => ['required', Rule::enum(ProgressMetric::class)],
            'value_milli' => ['required', 'integer', 'min:-1000000000', 'max:1000000000'],
            'unit' => ['required', 'string', Rule::in(['kg', 'percent', 'cm', 'count', 'seconds', 'metres', 'custom'])],
            'measured_at' => ['required', 'date', 'before_or_equal:now'],
            'note' => ['nullable', 'string', 'max:2000'],
            // A correction is sensitive historical evidence and always needs
            // an operator reason in the tenant audit log.
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
