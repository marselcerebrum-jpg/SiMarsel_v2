<?php

namespace App\Http\Requests\Account;

use App\Models\Account;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends StoreAccountRequest
{
    /**
     * Only the fields that are sent will be validated and updated.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'fullname' => ['sometimes', 'required', 'string', 'max:255'],
            'username' => [
                'sometimes',
                'required',
                'string',
                'regex:'.Account::USERNAME_FORMAT,
                Rule::unique('accounts', 'username')->ignore($this->route('account')),
            ],
            'password' => ['sometimes', 'required', 'string', 'min:8', 'max:255'],
            'role_id' => ['sometimes', 'required', 'integer', Rule::exists('roles', 'id')],
            'division_id' => ['sometimes', 'nullable', 'integer', Rule::exists('divisions', 'id')],
        ];
    }
}
