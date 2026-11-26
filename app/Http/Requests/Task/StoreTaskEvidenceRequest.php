<?php

namespace App\Http\Requests\Task;

use App\Rules\EvidenceFileMatchesContent;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTaskEvidenceRequest extends FormRequest
{
    /**
     * File types a PIC may attach as evidence: Word and PDF documents, and JPG or PNG images.
     */
    public const MIMES = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];

    /**
     * Largest evidence file, in kilobytes, from config/tasklist.php.
     */
    public static function maxKilobytes(): int
    {
        return (int) config('tasklist.evidence.max_kilobytes');
    }

    /**
     * The size limit as people read it, e.g. "10 MB" or "500 KB".
     */
    public static function maxSizeLabel(): string
    {
        $kilobytes = self::maxKilobytes();

        return $kilobytes % 1024 === 0 ? ($kilobytes / 1024).' MB' : $kilobytes.' KB';
    }

    private const TYPE_MESSAGE = 'Jenis file tidak didukung. Format yang diperbolehkan: PDF, DOC, DOCX, JPG, JPEG, atau PNG.';

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
            // mimes checks the type detected from the content, extensions the name, and the last rule that both agree.
            'file' => [
                'required_without:url',
                'prohibits:url',
                'file',
                'mimes:'.implode(',', self::MIMES),
                'extensions:'.implode(',', self::MIMES),
                'max:'.self::maxKilobytes(),
                new EvidenceFileMatchesContent,
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
            'file.uploaded' => 'File bukti gagal diunggah. Ukuran file maksimal '.self::maxSizeLabel().'.',
            'file.mimes' => self::TYPE_MESSAGE,
            'file.extensions' => self::TYPE_MESSAGE,
            'file.max' => 'Ukuran file maksimal '.self::maxSizeLabel().'.',
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
