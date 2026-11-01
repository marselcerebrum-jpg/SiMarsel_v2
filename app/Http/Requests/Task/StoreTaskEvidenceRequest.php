<?php

namespace App\Http\Requests\Task;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class StoreTaskEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->hasFile('file') && $this->filled('url')) {
            throw ValidationException::withMessages([
                'evidence' => 'Kirim file atau tautan, jangan keduanya.',
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'file' => ['required_without:url', 'prohibited_with:url', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx,ppt,pptx,zip'],
            'url' => ['required_without:file', 'prohibited_with:file', 'string', 'url:http,https', 'max:2048'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'required_without' => 'Lampirkan file atau tautan bukti.',
            'prohibited_with' => 'Kirim file atau tautan, jangan keduanya.',
            'file.mimes' => 'Jenis file tidak didukung. Format yang diperbolehkan: PDF, JPG, JPEG, PNG, WEBP, DOC, DOCX, XLS, XLSX, PPT, PPTX, atau ZIP.',
            'file.max' => 'Ukuran file maksimal 10 MB.',
            'url.url' => 'Tautan harus berupa URL HTTP atau HTTPS yang valid.',
            'note.max' => 'Catatan maksimal :max karakter.',
        ];
    }
}
