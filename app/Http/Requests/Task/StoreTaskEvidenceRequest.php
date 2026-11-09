<?php

namespace App\Http\Requests\Task;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTaskEvidenceRequest extends FormRequest
{
    /**
     * File types a PIC may attach as evidence.
     */
    public const MIMES = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip'];

    /**
     * Largest evidence file, in kilobytes (10 MB).
     */
    public const MAX_KILOBYTES = 10240;

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
            'file' => [
                'required_without:url',
                'prohibits:url',
                'file',
                'mimes:'.implode(',', self::MIMES),
                'max:'.self::MAX_KILOBYTES,
            ],
            'url' => ['required_without:file', 'nullable', 'string', 'max:2048', 'url:http,https'],
            'note' => ['nullable', 'string', 'max:2000'],
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
            'required_without' => 'Lampirkan file atau tautan bukti.',
            'file.prohibits' => 'Kirim file atau tautan saja, tidak keduanya.',
            'file.file' => 'File bukti gagal diunggah. Coba pilih file kembali.',
            'file.uploaded' => 'File bukti gagal diunggah. Ukuran file maksimal 10 MB.',
            'file.mimes' => 'Jenis file tidak didukung. Format yang diperbolehkan: PDF, JPG, JPEG, PNG, WEBP, DOC, DOCX, XLS, XLSX, PPT, PPTX, atau ZIP.',
            'file.max' => 'Ukuran file maksimal 10 MB.',
            'url.url' => 'Tautan harus berupa URL yang valid dan diawali http:// atau https://.',
            'string' => ':attribute harus berupa teks.',
            'max' => ':attribute maksimal :max karakter.',
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
            'file' => 'File bukti',
            'url' => 'Tautan bukti',
            'note' => 'Catatan',
        ];
    }
}
