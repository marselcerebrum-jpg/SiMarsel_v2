<?php

namespace App\Http\Requests\Performance;

use App\Enums\PeriodPreset;
use App\Enums\TrendPeriod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The Omzet and Transaksi trend reads the shared period resolver, so a preset is required.
 * The granularity is optional and defaults to the one of the resolved period.
 */
class PerformanceSalesTrendRequest extends PerformanceFilterRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'preset' => ['required', Rule::in(array_column(PeriodPreset::cases(), 'value'))],
            'granularity' => ['nullable', Rule::in(array_column(TrendPeriod::cases(), 'value'))],
        ];
    }

    /**
     * Hourly buckets are limited to 31 days, daily and weekly buckets to 366 days.
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

                $granularity = $this->granularity();
                $maxDays = $granularity->maxDays();

                if ($maxDays !== null && $this->filter()->period->active->days() > $maxDays) {
                    $validator->errors()->add(
                        'granularity',
                        "Rentang tanggal untuk pengelompokan {$granularity->value} maksimal {$maxDays} hari.",
                    );
                }
            },
        ];
    }

    /**
     * The selected granularity, or the default of the resolved period.
     */
    public function granularity(): TrendPeriod
    {
        return $this->filled('granularity')
            ? TrendPeriod::from($this->input('granularity'))
            : TrendPeriod::defaultFor($this->filter()->period);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'preset.required' => ':attribute wajib diisi.',
            'granularity.in' => ':attribute tidak valid.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'granularity' => 'Pengelompokan',
        ];
    }
}
