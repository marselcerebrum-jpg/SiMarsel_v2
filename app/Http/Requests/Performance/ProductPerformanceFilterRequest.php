<?php

namespace App\Http\Requests\Performance;

use Illuminate\Contracts\Validation\ValidationRule;

class ProductPerformanceFilterRequest extends ProductPerformanceRequest
{
    /**
     * Only the platform narrows the filter options; year and month are what this endpoint returns.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'platform' => parent::rules()['platform'],
        ];
    }
}
