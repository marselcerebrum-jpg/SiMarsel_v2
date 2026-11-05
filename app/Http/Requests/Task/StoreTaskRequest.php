<?php

namespace App\Http\Requests\Task;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Account;
use App\Models\Division;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    /**
     * Authorization is handled by TaskPolicy on the route.
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
        return [
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => ['nullable', 'integer', Rule::exists('task_categories', 'id')],
            'division_id' => ['required', 'integer', Rule::exists('divisions', 'id'), $this->creatableDivision()],
            'platform_id' => ['nullable', 'integer', Rule::exists('platforms', 'id')],
            'pic_id' => [
                'nullable',
                Rule::requiredIf($this->input('status') === TaskStatus::Todo->value),
                'integer',
                $this->assignablePic(),
            ],
            'priority' => ['nullable', Rule::in(array_column(TaskPriority::cases(), 'value'))],
            'status' => ['nullable', Rule::in(array_column(TaskStatus::initial(), 'value'))],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'deadline' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:today',
                ...($this->filled('start_date') ? ['after_or_equal:start_date'] : []),
            ],
            'requires_review' => ['nullable', 'boolean'],
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
            'required' => ':attribute wajib diisi.',
            'pic_id.required' => 'PIC wajib diisi untuk tugas berstatus To Do.',
            'string' => ':attribute harus berupa teks.',
            'max' => ':attribute maksimal :max karakter.',
            'integer' => ':attribute tidak valid.',
            'boolean' => ':attribute tidak valid.',
            'exists' => ':attribute tidak ditemukan.',
            'priority.in' => ':attribute harus Low, Medium, High, atau Urgent.',
            'status.in' => ':attribute harus Back Log atau To Do.',
            'date_format' => ':attribute harus berformat YYYY-MM-DD.',
            'deadline.after_or_equal' => 'Deadline tidak boleh sebelum hari ini atau sebelum tanggal mulai.',
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
            'title' => 'Judul tugas',
            'description' => 'Deskripsi',
            'category_id' => 'Kategori',
            'division_id' => 'Divisi',
            'platform_id' => 'Platform',
            'pic_id' => 'PIC',
            'priority' => 'Prioritas',
            'status' => 'Status awal',
            'start_date' => 'Tanggal mulai',
            'deadline' => 'Deadline',
            'requires_review' => 'Kewajiban QA',
        ];
    }

    /**
     * The division the task belongs to after this request.
     */
    protected function divisionId(): mixed
    {
        return $this->input('division_id');
    }

    /**
     * A Manager may only create tasks for the division they manage.
     */
    protected function creatableDivision(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! is_numeric($value) || ! Division::whereKey($value)->exists()) {
                return;
            }

            if (! Division::query()->taskCreatableBy($this->user())->whereKey($value)->exists()) {
                $fail('Anda hanya dapat membuat tugas untuk divisi yang Anda kelola.');
            }
        };
    }

    /**
     * The PIC must be a Staff member of the task's division.
     */
    protected function assignablePic(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $divisionId = $this->divisionId();

            if (! is_numeric($value) || ! is_numeric($divisionId)) {
                return;
            }

            if (! Account::query()->assignableIn((int) $divisionId)->whereKey($value)->exists()) {
                $fail('PIC harus Staff dari divisi yang dipilih.');
            }
        };
    }
}
