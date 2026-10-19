<?php

namespace App\Http\Requests\Performance;

use App\Support\PerformanceFilter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The main Ringkasan Performa filter, shared by every performance endpoint.
 * At most one period mode may be used: date range, month, years, or year range; none means the current month.
 */
class PerformanceFilterRequest extends FormRequest
{
    public const MIN_YEAR = 2000;

    public const MAX_YEARS = 5;

    /**
     * Query parameters that belong to each period mode.
     */
    private const PERIOD_MODES = [
        PerformanceFilter::MODE_DATE_RANGE => ['date_from', 'date_to'],
        PerformanceFilter::MODE_MONTH => ['year', 'month'],
        PerformanceFilter::MODE_YEARS => ['years'],
        PerformanceFilter::MODE_YEAR_RANGE => ['year_from', 'year_to'],
    ];

    /**
     * Every authenticated account may view the performance summary.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $year = ['integer', 'between:'.self::MIN_YEAR.','.CarbonImmutable::today()->year];

        return [
            'date_from' => ['required_with:date_to', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['required_with:date_from', 'nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'year' => ['required_with:month', 'nullable', ...$year],
            'month' => ['required_with:year', 'nullable', 'integer', 'between:1,12'],
            'years' => ['nullable', 'array', 'max:'.self::MAX_YEARS],
            'years.*' => ['distinct', ...$year],
            'year_from' => ['required_with:year_to', 'nullable', ...$year],
            'year_to' => ['required_with:year_from', 'nullable', ...$year, 'gte:year_from'],
            'platform' => ['nullable', 'string', Rule::exists('platforms', 'slug')],
            'platforms' => ['nullable', 'array'],
            'platforms.*' => ['string', 'distinct', Rule::exists('platforms', 'slug')],
        ];
    }

    /**
     * Only one period mode may be active at a time.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (count($this->selectedPeriodModes()) > 1) {
                    $validator->errors()->add(
                        'filter',
                        'Pilih salah satu filter periode saja: rentang tanggal, bulan, tahun, atau rentang tahun.',
                    );
                }
            },
        ];
    }

    /**
     * The filter from the request input; the old single `platform` parameter is merged into `platforms`.
     * Reads input() rather than validated() so it can also run inside after() hooks, once the rules have passed.
     */
    public function filter(): PerformanceFilter
    {
        $platforms = array_values(array_unique(array_filter([
            ...(array) $this->input('platforms', []),
            $this->input('platform'),
        ])));

        return match ($this->selectedPeriodModes()[0] ?? null) {
            PerformanceFilter::MODE_DATE_RANGE => PerformanceFilter::dateRange(
                CarbonImmutable::createFromFormat('Y-m-d', $this->input('date_from')),
                CarbonImmutable::createFromFormat('Y-m-d', $this->input('date_to')),
                $platforms,
            ),
            PerformanceFilter::MODE_MONTH => PerformanceFilter::month(
                (int) $this->input('year'),
                (int) $this->input('month'),
                $platforms,
            ),
            PerformanceFilter::MODE_YEARS => PerformanceFilter::years(
                array_map('intval', $this->input('years')),
                $platforms,
            ),
            PerformanceFilter::MODE_YEAR_RANGE => PerformanceFilter::yearRange(
                (int) $this->input('year_from'),
                (int) $this->input('year_to'),
                $platforms,
            ),
            default => PerformanceFilter::currentMonth($platforms),
        };
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required_with' => ':attribute wajib diisi.',
            'date_format' => ':attribute harus berformat YYYY-MM-DD.',
            'after_or_equal' => ':attribute tidak boleh sebelum tanggal mulai.',
            'integer' => ':attribute harus berupa angka.',
            'between' => ':attribute harus di antara :min dan :max.',
            'gte' => ':attribute tidak boleh sebelum tahun mulai.',
            'array' => ':attribute harus berupa daftar.',
            'years.max' => 'Tahun maksimal :max pilihan.',
            'distinct' => ':attribute tidak boleh dipilih lebih dari sekali.',
            'string' => ':attribute harus berupa teks.',
            'exists' => ':attribute tidak ditemukan.',
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
            'date_from' => 'Tanggal mulai',
            'date_to' => 'Tanggal akhir',
            'year' => 'Tahun',
            'month' => 'Bulan',
            'years' => 'Tahun',
            'years.*' => 'Tahun',
            'year_from' => 'Tahun mulai',
            'year_to' => 'Tahun akhir',
            'platform' => 'Platform',
            'platforms' => 'Platform',
            'platforms.*' => 'Platform',
        ];
    }

    /**
     * @return list<string>
     */
    private function selectedPeriodModes(): array
    {
        return array_keys(array_filter(
            self::PERIOD_MODES,
            fn (array $fields) => collect($fields)->contains(fn (string $field) => $this->filled($field)),
        ));
    }
}
