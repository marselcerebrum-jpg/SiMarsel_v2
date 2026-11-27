<?php

namespace App\Http\Requests\Performance;

use App\Services\PeakPurchaseHourService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

/**
 * Ekspor Laporan Dashboard (FIX-14-BE-01): the main filter with a required preset, the trend granularity (same rules
 * as the trend, PerformanceSalesTrendRequest), and the Jam Ramai day of the week (same rules as
 * PurchaseTimeDistributionRequest). The workbook is always Excel.
 */
class DashboardReportRequest extends PerformanceSalesTrendRequest
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
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty() || $this->dayOfWeek() === null) {
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
