<?php

namespace App\Http\Requests\Account;

use App\Models\Account;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
{
    /**
     * Authorization is handled by AccountPolicy on the route.
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
            'fullname' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'regex:'.Account::USERNAME_FORMAT, Rule::unique('accounts', 'username')],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'division_id' => ['nullable', 'integer', Rule::exists('divisions', 'id')],
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
            'integer' => ':attribute harus berupa angka.',
            'min' => ':attribute minimal :min karakter.',
            'max' => ':attribute maksimal :max karakter.',
            'unique' => ':attribute sudah digunakan.',
            'exists' => ':attribute tidak ditemukan.',
            'username.regex' => 'Username harus 4-25 karakter dan hanya boleh berisi huruf, angka, titik, atau underscore.',
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
            'fullname' => 'Nama lengkap',
            'username' => 'Username',
            'password' => 'Password',
            'role_id' => 'Role',
            'division_id' => 'Divisi',
        ];
    }
}
