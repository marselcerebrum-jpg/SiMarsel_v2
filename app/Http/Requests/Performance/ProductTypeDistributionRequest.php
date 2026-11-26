<?php

namespace App\Http\Requests\Performance;

use App\Services\TransactionCompositionService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Perdana vs Perpanjangan: the bar metric is optional and starts on Transaksi Berhasil.
 */
class ProductTypeDistributionRequest extends TransactionStatusDistributionRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'metric' => ['nullable', Rule::in(array_keys(TransactionCompositionService::METRICS))],
        ];
    }

    public function metric(): string
    {
        return $this->input('metric') ?: TransactionCompositionService::METRIC_SUCCESS_PAYMENTS;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'metric.in' => ':attribute tidak valid.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'metric' => 'Metrik',
        ];
    }
}
