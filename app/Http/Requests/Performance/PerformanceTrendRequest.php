<?php

namespace App\Http\Requests\Performance;

use App\Enums\TrendMetric;
use App\Enums\TrendPeriod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PerformanceTrendRequest extends PerformanceFilterRequest
{
    /**
     * Uses the performance filters plus the selected metric and an optional period.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'metric' => ['required', Rule::in(array_column(TrendMetric::cases(), 'value'))],
            'period' => ['nullable', Rule::in(array_column(TrendPeriod::cases(), 'value'))],
        ];
    }

    /**
     * Limit the range for periods that would otherwise produce too many data points.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $period = $this->period();
                $filter = $this->filter();

                if ($filter->comparesYears() && $period !== TrendPeriod::Monthly) {
                    $validator->errors()->add('period', 'Perbandingan beberapa tahun hanya mendukung periode monthly.');

                    return;
                }

                $maxDays = $period->maxDays();

                if ($maxDays !== null && $filter->days() > $maxDays) {
                    $validator->errors()->add(
                        'date_to',
                        "Rentang tanggal untuk periode {$period->value} maksimal {$maxDays} hari.",
                    );
                }
            },
        ];
    }

    /**
     * The selected period, or the filter's default: daily for a month or date range, monthly for years.
     */
    public function period(): TrendPeriod
    {
        return $this->filled('period')
            ? TrendPeriod::from($this->input('period'))
            : $this->filter()->defaultPeriod;
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
            'required' => ':attribute wajib diisi.',
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
