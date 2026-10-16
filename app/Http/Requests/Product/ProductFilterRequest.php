<?php

namespace App\Http\Requests\Product;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class ProductFilterRequest extends ProductOverviewRequest
{
    /**
     * Only the platform narrows the filter options; year and month are what this endpoint returns.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'platform' => ['nullable', 'string', Rule::exists('platforms', 'slug')],
        ];
    }
}
