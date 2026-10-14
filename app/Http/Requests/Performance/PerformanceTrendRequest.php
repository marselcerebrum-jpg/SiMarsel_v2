<?php

namespace App\Http\Requests\Performance;

use App\Enums\TrendMetric;
use App\Enums\TrendPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PerformanceTrendRequest extends PerformanceSummaryRequest
{
    /**
     * Uses the performance summary filters plus the selected metric and period.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'metric' => ['required', Rule::in(array_column(TrendMetric::cases(), 'value'))],
            'period' => ['required', Rule::in(array_column(TrendPeriod::cases(), 'value'))],
        ];
    }

    /**
     * Limit the date range for periods that would otherwise produce too many data points.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['date_from', 'date_to', 'period'])) {
                    return;
                }

                $period = TrendPeriod::from($this->input('period'));
                $maxDays = $period->maxDays();

                if ($maxDays === null) {
                    return;
                }

                $from = CarbonImmutable::createFromFormat('Y-m-d', $this->input('date_from'))->startOfDay();
                $to = CarbonImmutable::createFromFormat('Y-m-d', $this->input('date_to'))->startOfDay();

                if ((int) $from->diffInDays($to) + 1 > $maxDays) {
                    $validator->errors()->add(
                        'date_to',
                        "Rentang tanggal untuk periode {$period->value} maksimal {$maxDays} hari.",
                    );
                }
            },
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'in' => ':attribute tidak valid.',
        ];
    }

    /**
     * Get custom attribute names for validation errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'metric' => 'Metric',
            'period' => 'Periode',
        ];
    }
}
