<?php

namespace App\Http\Requests\Task;

use App\Models\Task;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class AssignTaskRequest extends StoreTaskRequest
{
    /**
     * pic_id is the new PIC; division_id is sent only when the task is forwarded to another division.
     * Who may forward where, and the status restrictions, are checked by TaskService.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'division_id' => ['sometimes', 'required', 'integer', Rule::exists('divisions', 'id')],
            'pic_id' => ['required', 'integer', $this->assignablePic()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'pic_id.required' => 'PIC wajib dipilih.',
            'version.required' => 'Versi tugas wajib dikirim. Muat ulang detail tugas.',
            'version.integer' => 'Versi tugas tidak valid. Muat ulang detail tugas.',
            'version.min' => 'Versi tugas tidak valid. Muat ulang detail tugas.',
        ];
    }

    protected function divisionId(): mixed
    {
        return $this->input('division_id', $this->task()->division_id);
    }

    private function task(): Task
    {
        return $this->route('task');
    }
}
