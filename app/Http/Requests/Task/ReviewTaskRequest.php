<?php

namespace App\Http\Requests\Task;

use App\Enums\TaskReviewDecision;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewTaskRequest extends FormRequest
{
    /**
     * Authorization is handled by TaskPolicy on the route.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * version is the task version the decision was based on; whether the task can be decided on now is
     * checked by TaskReviewService. A note made only of spaces is trimmed to nothing, so it counts as missing.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'decision' => ['required', Rule::enum(TaskReviewDecision::class)],
            'note' => ['nullable', 'required_if:decision,'.TaskReviewDecision::Revision->value, 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'version.required' => 'Versi tugas wajib dikirim. Muat ulang detail tugas.',
            'version.integer' => 'Versi tugas tidak valid. Muat ulang detail tugas.',
            'version.min' => 'Versi tugas tidak valid. Muat ulang detail tugas.',
            'decision.required' => 'Keputusan review wajib dipilih.',
            'decision.enum' => 'Keputusan review tidak valid.',
            'note.required_if' => 'Catatan alasan revisi wajib diisi.',
            'note.string' => 'Catatan review tidak valid.',
            'note.max' => 'Catatan review maksimal 2000 karakter.',
        ];
    }
}
