<?php

namespace App\Http\Requests\Task;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaskListRequest extends FormRequest
{
    public const SORTS = ['title', 'division', 'pic', 'priority', 'status', 'start_date', 'deadline', 'created_at', 'updated_at'];

    public const MAX_PER_PAGE = 100;

    /**
     * Access is decided per row by the visibility scope, so every account may ask for the list.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Search, filter, sort, and optional pagination for the Board and Table views.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:150'],
            'division' => ['nullable', 'string', Rule::exists('divisions', 'code_division')],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'status' => ['nullable', Rule::enum(TaskStatus::class)],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'between:1,'.self::MAX_PER_PAGE],
            'page' => ['nullable', 'integer', 'min:1'],
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
            'string' => ':attribute harus berupa teks.',
            'integer' => ':attribute harus berupa angka.',
            'exists' => ':attribute tidak ditemukan.',
            'in' => ':attribute tidak valid.',
            'enum' => ':attribute tidak valid.',
            'search.max' => ':attribute maksimal :max karakter.',
            'per_page.between' => ':attribute harus antara 1 sampai :max.',
            'page.min' => ':attribute minimal 1.',
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
            'search' => 'Pencarian',
            'division' => 'Divisi',
            'priority' => 'Prioritas',
            'status' => 'Status',
            'sort' => 'Urutan',
            'direction' => 'Arah urutan',
            'per_page' => 'Jumlah per halaman',
            'page' => 'Halaman',
        ];
    }

    /**
     * @return array{search: ?string, division: ?string, priority: ?string, status: ?string}
     */
    public function filters(): array
    {
        return [
            'search' => $this->validated('search'),
            'division' => $this->validated('division'),
            'priority' => $this->validated('priority'),
            'status' => $this->validated('status'),
        ];
    }

    public function sort(): string
    {
        return $this->validated('sort') ?? 'created_at';
    }

    /**
     * Newest first by default; ascending once the user picks a column to sort by.
     */
    public function direction(): string
    {
        return $this->validated('direction') ?? ($this->validated('sort') ? 'asc' : 'desc');
    }

    /**
     * Rows per page, or null to return every task in scope as one list.
     */
    public function perPage(): ?int
    {
        $perPage = $this->validated('per_page');

        return $perPage === null ? null : (int) $perPage;
    }
}
