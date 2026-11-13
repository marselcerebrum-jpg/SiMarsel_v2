<?php

namespace App\Http\Requests\Task;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class AssignTaskRequest extends StoreTaskRequest
{
    /**
     * pic_ids is the full list of PICs the task should have after the request. PICs not in the list are released and
     * new ones are added; PICs that stay are left untouched. division_id is sent only when Admin forwards the task to
     * another division. Who may assign, and the status restrictions, are checked by TaskService.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'division_id' => ['sometimes', 'required', 'integer', Rule::exists('divisions', 'id')],
            'pic_ids' => ['present', 'array', 'max:'.self::MAX_PICS],
            'pic_ids.*' => ['integer', 'distinct', $this->assignablePic()],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'pic_ids.present' => 'Daftar PIC wajib dikirim.',
            'version.required' => 'Versi tugas wajib dikirim. Muat ulang detail tugas.',
            'version.integer' => 'Versi tugas tidak valid. Muat ulang detail tugas.',
            'version.min' => 'Versi tugas tidak valid. Muat ulang detail tugas.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'note' => 'Catatan',
        ];
    }
}
