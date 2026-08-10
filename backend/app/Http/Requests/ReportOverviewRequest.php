<?php

namespace App\Http\Requests;

use App\Enums\Currency;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Throwable;

class ReportOverviewRequest extends TenantFormRequest
{
    protected function prepareForValidation(): void
    {
        $context = app(TenantContext::class);
        $today = CarbonImmutable::now($context->gym()->timezone)->startOfDay();

        // Defaults are calculated in the resolved gym timezone. The client
        // cannot widen tenant scope by supplying dates or a currency.
        $this->merge([
            'from' => $this->input('from', $today->subDays(29)->toDateString()),
            'to' => $this->input('to', $today->toDateString()),
            'currency' => strtoupper(trim((string) $this->input(
                'currency',
                $context->gym()->base_currency->value,
            ))),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'currency' => ['required', Rule::enum(Currency::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            try {
                $from = CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->input('from'));
                $to = CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->input('to'));
                if (! $from || ! $to || $to->lessThan($from)) {
                    return;
                }

                // A hard upper bound protects PostgreSQL and Redis from an
                // accidental multi-year aggregation at million-member scale.
                if ($from->diffInDays($to) + 1 > 366) {
                    $validator->errors()->add('to', 'The report period cannot exceed 366 days.');
                }
            } catch (Throwable) {
                // Field-level date rules provide the useful validation error.
            }
        });
    }
}
