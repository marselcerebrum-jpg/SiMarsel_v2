<?php

namespace App\Http\Requests\Promo;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create and update validation shared by the Funnel and Source masters: both only have a unique name.
 */
abstract class PromoMasterDataRequest extends FormRequest
{
    /**
     * Analysis groups made by the system, not master records.
     */
    public const SYSTEM_GROUPS = ['Tanpa Kode', 'Tanpa Funnel', 'Tanpa Sumber'];

    /**
     * The master table whose names must be unique.
     */
    abstract protected function table(): string;

    /**
     * The route parameter of the record being updated, if any.
     */
    abstract protected function routeKey(): string;

    /**
     * How the name is called in error messages, e.g. "Nama funnel".
     */
    abstract protected function nameLabel(): string;

    /**
     * Authorization is handled by the policy on the route.
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
        $current = $this->route($this->routeKey());

        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique($this->table(), 'name')->ignore($current instanceof Model ? $current->getKey() : null),
                function (string $attribute, mixed $value, Closure $fail) {
                    $reserved = array_map('mb_strtolower', self::SYSTEM_GROUPS);

                    if (is_string($value) && in_array(mb_strtolower(trim($value)), $reserved, true)) {
                        $fail(':attribute tidak boleh memakai nama kelompok sistem ('.implode(', ', self::SYSTEM_GROUPS).').');
                    }
                },
            ],
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
            'string' => ':attribute harus berupa teks.',
            'max' => ':attribute maksimal :max karakter.',
            'unique' => ':attribute sudah digunakan.',
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
            'name' => $this->nameLabel(),
        ];
    }
}
