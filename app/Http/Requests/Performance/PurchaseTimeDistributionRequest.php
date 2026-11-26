<?php

namespace App\Http\Requests\Performance;

use App\Services\PeakPurchaseHourService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

/**
 * The main performance filter plus the local day choice of Jam Ramai Pembelian: day_of_week is an ISO day of the
 * week (1 = Senin ... 7 = Minggu) applied to the active period and its comparison. It is read only with a preset,
 * and ignored for a one-day period, which has no day choice.
 */
class PurchaseTimeDistributionRequest extends PerformanceFilterRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'day_of_week' => ['nullable', 'integer', 'between:1,7'],
        ];
    }

    /**
     * A chosen day must be one of the days of the active period.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty() || $this->dayOfWeek() === null || $this->filter()->period === null) {
                    return;
                }

                $options = PeakPurchaseHourService::dayOptions($this->filter()->period);

                if ($options !== [] && ! in_array($this->dayOfWeek(), $options, true)) {
                    $validator->errors()->add('day_of_week', 'Hari yang dipilih tidak ada dalam periode aktif.');
                }
            },
        ];
    }

    public function dayOfWeek(): ?int
    {
        return $this->filled('day_of_week') ? (int) $this->input('day_of_week') : null;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'day_of_week' => 'Hari',
        ];
    }
}
