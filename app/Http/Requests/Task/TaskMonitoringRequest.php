<?php

namespace App\Http\Requests\Task;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaskMonitoringRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'division' => ['nullable', 'string', Rule::exists('divisions', 'code_division')],
            'status' => ['nullable', Rule::enum(TaskStatus::class)],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'date_from' => ['required_with:date_to', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['required_with:date_from', 'nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];
    }

    /** @return array{division: ?string, status: ?string, priority: ?string, date_from: ?string, date_to: ?string} */
    public function filters(): array
    {
        return [
            'division' => $this->validated('division'),
            'status' => $this->validated('status'),
            'priority' => $this->validated('priority'),
            'date_from' => $this->validated('date_from'),
            'date_to' => $this->validated('date_to'),
        ];
    }
}
