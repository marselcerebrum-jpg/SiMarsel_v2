<?php

namespace App\Http\Requests\Task;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaskTimelineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period' => ['sometimes', 'string', Rule::in(['week', 'two_weeks'])],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'division' => ['sometimes', 'string', Rule::exists('divisions', 'code_division')],
        ];
    }

    public function period(): string
    {
        return $this->validated('period') ?? 'week';
    }

    public function date(): string
    {
        return $this->validated('date') ?? now()->toDateString();
    }
}
