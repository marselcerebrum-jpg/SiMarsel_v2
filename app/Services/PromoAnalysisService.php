<?php

namespace App\Services;

use App\Repositories\PromoAnalysisRepository;
use App\Services\Concerns\ComparesWithPreviousValue;
use App\Support\PromoAnalysisFilter;

class PromoAnalysisService
{
    use ComparesWithPreviousValue;

    public function __construct(
        private PromoAnalysisRepository $promoAnalysisRepository,
        private PlatformService $platformService,
    ) {}

    /**
     * Options for the Analisis Promo filter: the global platforms and the payment methods found in transactions.
     *
     * @return array<string, mixed>
     */
    public function filterOptions(): array
    {
        return [
            'platforms' => $this->platformService->list(),
            'payment_methods' => $this->promoAnalysisRepository->paymentMethods()
                ->map(fn (?string $name) => filled($name) ? $name : PaymentMethodDistributionService::UNKNOWN_METHOD)
                ->unique()
                ->sort()
                ->values()
                ->all(),
        ];
    }

    /**
     * The KPI cards for the filter, compared with the filter's previous period.
     *
     * @return array<string, mixed>
     */
    public function summarize(PromoAnalysisFilter $filter): array
    {
        $current = $this->metrics($filter);
        $previous = $this->metrics($filter->previous());

        $isEmpty = $current['promo_transactions'] + $current['non_promo_transactions'] === 0;

        return [
            'filter' => $filter->toArray(),
            'previous_period' => $filter->previousToArray(),
            'is_empty' => $isEmpty,
            'message' => $isEmpty ? 'Data tidak tersedia untuk filter yang dipilih.' : null,
            'kpis' => collect($current)
                ->map(fn (int|float|null $value, string $key) => [
                    'value' => $value,
                    'previous_value' => $previous[$key],
                    ...$this->changeFromPrevious($value, $previous[$key]),
                ])
                ->all(),
        ];
    }

    /**
     * @return array<string, int|float|null>
     */
    private function metrics(PromoAnalysisFilter $filter): array
    {
        $totals = $this->promoAnalysisRepository->transactionTotals($filter);

        return [
            'promo_transactions' => $totals['promo_transactions'],
            'non_promo_transactions' => $totals['non_promo_transactions'],
            'promo_revenue' => round($totals['promo_revenue'], 2),
            'non_promo_revenue' => round($totals['non_promo_revenue'], 2),
            'promo_transaction_percentage' => $totals['transactions'] > 0
                ? round($totals['promo_transactions'] / $totals['transactions'] * 100, 2)
                : null,
            'used_promo_codes' => $this->promoAnalysisRepository->countUsedCodes($filter),
        ];
    }
}
