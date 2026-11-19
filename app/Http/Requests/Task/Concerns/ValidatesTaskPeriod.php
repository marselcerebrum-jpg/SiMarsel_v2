<?php

namespace App\Http\Requests\Task\Concerns;

use App\Support\TaskPeriod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * The period and PIC filters shared by the task list and the KPIs. Exactly one mode is active, and a parameter that
 * belongs to another mode is rejected, so one request can only mean one period.
 */
trait ValidatesTaskPeriod
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function periodRules(): array
    {
        return [
            'pic_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')],
            'filter_mode' => ['nullable', 'required_with:date_field,date,month,year,start_date,end_date', Rule::in(TaskPeriod::MODES)],
            'date_field' => ['nullable', 'required_with:filter_mode', Rule::in(TaskPeriod::FIELDS)],
            'date' => ['nullable', 'required_if:filter_mode,date', 'prohibited_unless:filter_mode,date', 'date_format:Y-m-d'],
            'month' => ['nullable', 'required_if:filter_mode,month', 'prohibited_unless:filter_mode,month', 'integer', 'between:1,12'],
            'year' => ['nullable', 'required_if:filter_mode,month,year', 'prohibited_unless:filter_mode,month,year', 'integer', 'between:2000,2100'],
            'start_date' => ['nullable', 'required_if:filter_mode,range', 'prohibited_unless:filter_mode,range', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'required_if:filter_mode,range', 'prohibited_unless:filter_mode,range', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function periodMessages(): array
    {
        return [
            'integer' => ':attribute harus berupa angka.',
            'date_format' => ':attribute harus berformat YYYY-MM-DD.',
            'required_if' => ':attribute wajib diisi untuk mode filter ini.',
            'prohibited_unless' => ':attribute tidak digunakan pada mode filter ini.',
            'filter_mode.required_with' => 'Mode filter periode wajib dipilih.',
            'date_field.required_with' => 'Dasar tanggal wajib dipilih.',
            'month.between' => ':attribute harus antara 1 sampai 12.',
            'year.between' => ':attribute harus antara 2000 sampai 2100.',
            'end_date.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function periodAttributes(): array
    {
        return [
            'pic_id' => 'PIC',
            'filter_mode' => 'Mode filter periode',
            'date_field' => 'Dasar tanggal',
            'date' => 'Tanggal',
            'month' => 'Bulan',
            'year' => 'Tahun',
            'start_date' => 'Tanggal awal',
            'end_date' => 'Tanggal akhir',
        ];
    }

    protected function period(): ?TaskPeriod
    {
        return TaskPeriod::fromInput($this->validated());
    }

    protected function picId(): ?int
    {
        $picId = $this->validated('pic_id');

        return $picId === null ? null : (int) $picId;
    }
}
