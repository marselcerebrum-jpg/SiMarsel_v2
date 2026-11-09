<?php

namespace App\Http\Requests\Task;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Services\TaskMonitoringService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaskMonitoringRequest extends FormRequest
{
    /**
     * Every role may read the monitoring report; the scope decides which tasks are counted.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'scope' => ['nullable', Rule::in([TaskMonitoringService::SCOPE_REPORT, TaskMonitoringService::SCOPE_TASKLIST])],
            'search' => ['nullable', 'string', 'max:150'],
            'division' => ['nullable', 'string', Rule::exists('divisions', 'code_division')],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'status' => ['nullable', Rule::enum(TaskStatus::class)],
            'date_basis' => ['nullable', Rule::in(TaskMonitoringService::DATE_BASES)],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'string' => ':attribute harus berupa teks.',
            'exists' => ':attribute tidak ditemukan.',
            'in' => ':attribute tidak valid.',
            'enum' => ':attribute tidak valid.',
            'date_format' => ':attribute harus berformat YYYY-MM-DD.',
            'search.max' => ':attribute maksimal :max karakter.',
            'date_to.after_or_equal' => ':attribute tidak boleh sebelum Tanggal mulai.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'scope' => 'Cakupan',
            'search' => 'Pencarian',
            'division' => 'Divisi',
            'priority' => 'Prioritas',
            'status' => 'Status',
            'date_basis' => 'Dasar tanggal',
            'date_from' => 'Tanggal mulai',
            'date_to' => 'Tanggal akhir',
        ];
    }

    /**
     * The whole report across divisions unless the Tasklist scope is asked for.
     */
    public function scope(): string
    {
        return $this->validated('scope') ?? TaskMonitoringService::SCOPE_REPORT;
    }

    /**
     * The period is matched on the deadline unless the creation date is asked for.
     *
     * @return array{search: ?string, division: ?string, priority: ?string, status: ?string, date_basis: string, date_from: ?string, date_to: ?string}
     */
    public function filters(): array
    {
        return [
            'search' => $this->validated('search'),
            'division' => $this->validated('division'),
            'priority' => $this->validated('priority'),
            'status' => $this->validated('status'),
            'date_basis' => $this->validated('date_basis') ?? TaskMonitoringService::DATE_BASIS_DEADLINE,
            'date_from' => $this->validated('date_from'),
            'date_to' => $this->validated('date_to'),
        ];
    }
}
