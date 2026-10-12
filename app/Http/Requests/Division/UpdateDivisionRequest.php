<?php

namespace App\Http\Requests\Division;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class UpdateDivisionRequest extends StoreDivisionRequest
{
    /**
     * Only the fields that are sent will be validated and updated.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code_division' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('divisions', 'code_division')->ignore($this->route('division')),
            ],
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:25',
                Rule::unique('divisions', 'name')->ignore($this->route('division')),
            ],
        ];
    }
}
