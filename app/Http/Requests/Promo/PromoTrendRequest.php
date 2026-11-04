<?php

namespace App\Http\Requests\Promo;

use App\Enums\TrendPeriod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The Analisis Promo filter plus the daily, weekly, or monthly chart period.
 */
class PromoTrendRequest extends PromoAnalysisFilterRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'period' => ['nullable', 'string', Rule::in(array_column(TrendPeriod::cases(), 'value'))],
        ];
    }

    /**
     * Limit the range for periods that would otherwise produce too many data points, like the performance trend.
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
                $maxDays = $period->maxDays();

                if ($maxDays !== null && $this->filter()->days() > $maxDays) {
                    $validator->errors()->add('date_to', "Rentang tanggal untuk periode {$period->value} maksimal {$maxDays} hari.");
                }
            },
        ];
    }

    /**
     * The selected period, or daily: every Analisis Promo period is at most a date range or one month.
     */
    public function period(): TrendPeriod
    {
        return $this->filled('period') ? TrendPeriod::from($this->input('period')) : $this->filter()->defaultPeriod;
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
            'period' => 'Periode',
        ];
    }
}
